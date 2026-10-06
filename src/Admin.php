<?php
declare(strict_types=1);
namespace Audit;
final class Admin {
    public static function filters(array $input): array {
        $f=['view'=>'activity','from'=>gmdate('Y-m-d',strtotime('-29 days')),'to'=>gmdate('Y-m-d'),'q'=>'','role'=>'all','state'=>'all','event'=>'all','before'=>''];
        foreach($f as $key=>$default)if(isset($input[$key])) {
            if(!is_string($input[$key]))throw new \InvalidArgumentException('Invalid research filter.');
            $f[$key]=trim($input[$key]);
        }
        foreach(['from','to'] as $key){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$f[$key],new \DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d')!==$f[$key])throw new \InvalidArgumentException('Choose valid research dates.');}
        if($f['from']>$f['to']||mb_strlen($f['q'])>150||!in_array($f['view'],['users','audits','activity'],true)||!in_array($f['state'],['all','running','complete','failed'],true)||($f['role']!=='all'&&$f['role']!=='not_recorded'&&!isset(Research::ROLES[$f['role']]))||($f['event']!=='all'&&!in_array($f['event'],Research::EVENTS,true)))throw new \InvalidArgumentException('Invalid research filter.');
        if($f['before']!==''&&!preg_match($f['view']==='audits'?'/^[a-f0-9]{32}$/':'/^[1-9][0-9]{0,19}$/',$f['before']))throw new \InvalidArgumentException('Invalid research page.');
        $f['start']=$f['from'].' 00:00:00';$f['end']=(new \DateTimeImmutable($f['to'],new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d').' 00:00:00';
        return $f;
    }
    public static function overview(array $f): array {
        $args=[$f['start'],$f['end']];
        $count=function($sql)use($args){$q=\db()->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();};
        $counts=['registrations'=>$count('SELECT COUNT(*) FROM users WHERE created_at>=? AND created_at<?'),'audits'=>$count('SELECT COUNT(*) FROM audits WHERE created_at>=? AND created_at<?'),'visitors'=>$count('SELECT COUNT(DISTINCT visitor_hash) FROM usage_events WHERE occurred_at>=? AND occurred_at<? AND visitor_hash<>\'\''),'events'=>$count('SELECT COUNT(*) FROM usage_events WHERE occurred_at>=? AND occurred_at<?')];
        $q=\db()->prepare("SELECT COALESCE(p.professional_role,'not_recorded') AS professional_role,COUNT(*) AS total FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.created_at>=? AND u.created_at<? GROUP BY p.professional_role ORDER BY total DESC");$q->execute($args);$roles=$q->fetchAll();
        $q=\db()->prepare("SELECT LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(NULLIF(final_url,''),requested_url),'://',-1),'/',1),'?',1),'@',-1)) AS site,COUNT(*) AS total FROM audits WHERE created_at>=? AND created_at<? GROUP BY site ORDER BY total DESC,site LIMIT 20");$q->execute($args);$sites=$q->fetchAll();
        foreach($sites as &$site){$url=Research::url('https://'.$site['site']);$site['site']=$url&&$url!=='(invalid URL)'?(parse_url($url,PHP_URL_HOST)?:'Unknown'):'Invalid URL';}unset($site);
        return ['counts'=>$counts,'roles'=>$roles,'sites'=>$sites,'from'=>$f['from'],'to'=>$f['to']];
    }
    public static function rows(array $f,int $limit=50): array {
        $limit=max(1,min(5000,$limit));$args=[$f['start'],$f['end']];
        if($f['view']==='users') {
            $from=' FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id LEFT JOIN leads l ON l.user_id=u.id';
            $select='SELECT u.id,u.email,u.created_at,p.professional_role,p.registration_ip AS connection_ip,p.user_agent,p.os_name,p.browser_name,p.device_type,p.language,l.marketing_consent,EXISTS(SELECT 1 FROM super_admins sa WHERE sa.user_id=u.id) AS is_super_admin,(SELECT COUNT(*) FROM audits a WHERE a.user_id=u.id) AS audit_count,(SELECT MAX(e.occurred_at) FROM usage_events e WHERE e.user_id=u.id) AS last_seen';
            $where='u.created_at>=? AND u.created_at<?';$id='u.id';$search=['u.email'];$order='u.id DESC';
        } elseif($f['view']==='audits') {
            $from=' FROM audits a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN user_profiles p ON p.user_id=u.id LEFT JOIN audit_context c ON c.audit_id=a.id';
            $select='SELECT a.id,a.created_at,a.completed_at,a.requested_url,a.final_url,a.state,a.score,a.visitor_hash,u.email,p.professional_role,c.connection_ip,c.user_agent,c.os_name,c.browser_name,c.device_type,c.language';
            $where='a.created_at>=? AND a.created_at<?';$id='a.id';$search=['a.requested_url','a.final_url','u.email'];$order='a.created_at DESC,a.id DESC';
            if($f['state']!=='all'){$where.=' AND a.state=?';$args[]=$f['state'];}
        } else {
            $from=' FROM usage_events e LEFT JOIN users u ON u.id=e.user_id LEFT JOIN user_profiles p ON p.user_id=u.id';
            $select='SELECT e.id,e.occurred_at,e.event_type,e.audit_id,e.visitor_hash,e.page_url,e.connection_ip,e.user_agent,e.os_name,e.browser_name,e.device_type,e.language,e.metadata_json,u.email,p.professional_role';
            $where='e.occurred_at>=? AND e.occurred_at<?';$id='e.id';$search=['e.page_url','u.email','e.connection_ip'];$order='e.id DESC';
            if($f['event']!=='all'){$where.=' AND e.event_type=?';$args[]=$f['event'];}
        }
        if($f['role']==='not_recorded')$where.=' AND p.professional_role IS NULL';
        elseif($f['role']!=='all'){$where.=' AND p.professional_role=?';$args[]=$f['role'];}
        if($f['q']!==''){
            $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$f['q']).'%';$clauses=[];
            foreach($search as $column){$clauses[]=$column." LIKE ? ESCAPE '!'";$args[]=$pattern;}
            $where.=' AND ('.implode(' OR ',$clauses).')';
        }
        $q=\db()->prepare('SELECT COUNT(*)'.$from.' WHERE '.$where);$q->execute($args);$total=(int)$q->fetchColumn();
        if($f['before']!=='') {
            if($f['view']==='audits') {
                $q=\db()->prepare('SELECT a.created_at'.$from.' WHERE '.$where.' AND a.id=?');$q->execute(array_merge($args,[$f['before']]));$date=$q->fetchColumn();
                if(!$date)return ['items'=>[],'total'=>$total,'nextCursor'=>null];
                $where.=' AND (a.created_at<? OR (a.created_at=? AND a.id<?))';$args=array_merge($args,[$date,$date,$f['before']]);
            } else {$where.=' AND '.$id.'<?';$args[]=$f['before'];}
        }
        $q=\db()->prepare($select.$from.' WHERE '.$where.' ORDER BY '.$order.' LIMIT '.($limit+1));$q->execute($args);$items=$q->fetchAll();$more=count($items)>$limit;$items=array_slice($items,0,$limit);
        foreach($items as &$item){
            if($f['view']==='audits'){$item['page_url']=Research::url($item['final_url']?:$item['requested_url']);unset($item['requested_url'],$item['final_url']);}
            if(isset($item['metadata_json'])){$item['metadata']=json_decode($item['metadata_json'],true);unset($item['metadata_json']);}
        }unset($item);
        return ['items'=>$items,'total'=>$total,'nextCursor'=>$more?(string)$items[count($items)-1]['id']:null];
    }
    public static function csvCell($value): string {
        $s=(string)($value??'');
        // Neutralise spreadsheet formula execution, including whitespace-prefixed payloads.
        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u',$s)||preg_match('/^[\t\r\n]/',$s)?"'".$s:$s;
    }
    public static function csv(array $items,string $view): string {
        $columns=$view==='users'?['id','email','professional_role','is_super_admin','created_at','last_seen','audit_count','marketing_consent','connection_ip','os_name','browser_name','device_type','language','user_agent']:($view==='audits'?['id','created_at','completed_at','email','professional_role','page_url','state','score','connection_ip','os_name','browser_name','device_type','language','user_agent','visitor_hash']:['id','occurred_at','event_type','email','professional_role','audit_id','page_url','connection_ip','os_name','browser_name','device_type','language','user_agent','visitor_hash','metadata']);
        $out=fopen('php://temp','w+');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$columns,',','"','');
        foreach($items as $item){$row=[];foreach($columns as $column){$value=$item[$column]??'';if($value&&in_array($column,['created_at','completed_at','occurred_at','last_seen'],true))$value=str_replace(' ','T',(string)$value).'Z';if(is_array($value))$value=json_encode($value,JSON_INVALID_UTF8_SUBSTITUTE);$row[]=self::csvCell($value);}fputcsv($out,$row,',','"','');}
        rewind($out);$csv=stream_get_contents($out);fclose($out);return $csv;
    }
}
