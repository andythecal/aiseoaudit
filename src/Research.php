<?php
declare(strict_types=1);
namespace Audit;
final class Research {
    const ROLES=['marketing'=>'Marketing','tech_advisor'=>'Tech advisor','ceo'=>'CEO / director','freelancer'=>'Freelancer','agency'=>'Agency','business_owner'=>'Business owner','developer'=>'Developer','seo_consultant'=>'SEO consultant','student'=>'Student / researcher','other'=>'Other'];
    const EVENTS=['visit','registered','login','logout','audit_started','audit_completed','audit_failed','deep_started','deep_completed','deep_failed','report_viewed','pdf_downloaded','json_exported','audit_deleted','browser_imported','browser_kept','admin_created','admin_report_viewed','admin_csv_exported'];
    public static function client(array $server): array {
        // REMOTE_ADDR only: never trust a caller-supplied forwarding header.
        $ip=$server['REMOTE_ADDR']??'';$ip=filter_var($ip,FILTER_VALIDATE_IP)?$ip:null;
        $ua=mb_substr(preg_replace('/[\x00-\x1f\x7f]/',' ',mb_convert_encoding((string)($server['HTTP_USER_AGENT']??''),'UTF-8','UTF-8')),0,512);
        $os='Unknown';$browser='Unknown';$device='Unknown';
        if($ua!=='') {
            $os=preg_match('/iPhone|iPad|iPod/i',$ua)?'iOS / iPadOS':(stripos($ua,'Android')!==false?'Android':(stripos($ua,'Windows')!==false?'Windows':(stripos($ua,'Macintosh')!==false?'macOS':(stripos($ua,'CrOS')!==false?'Chrome OS':(stripos($ua,'Linux')!==false?'Linux':'Unknown')))));
            $device=preg_match('/bot|crawler|spider|headless/i',$ua)?'Bot / automation':(preg_match('/iPad|Tablet/i',$ua)||(stripos($ua,'Android')!==false&&stripos($ua,'Mobile')===false)?'Tablet':(preg_match('/Mobile|iPhone|iPod/i',$ua)?'Mobile':($os!=='Unknown'?'Desktop':'Unknown')));
            foreach(['Edge'=>'/Edg(?:e|A|iOS)?\//i','Opera'=>'/OPR\//i','Firefox'=>'/(?:Firefox|FxiOS)\//i','Chrome'=>'/(?:Chrome|CriOS)\//i','Safari'=>'/Version\/.+Safari\//i'] as $name=>$pattern)if(preg_match($pattern,$ua)){$browser=$name;break;}
        }
        $language=trim(explode(',',mb_convert_encoding((string)($server['HTTP_ACCEPT_LANGUAGE']??''),'UTF-8','UTF-8'))[0]);$language=mb_substr(preg_replace('/[\x00-\x1f\x7f]/','',$language),0,80);
        return ['connection_ip'=>$ip,'user_agent'=>$ua?:null,'os_name'=>$ua!==''?$os:null,'browser_name'=>$ua!==''?$browser:null,'device_type'=>$ua!==''?$device:null,'language'=>$language?:null];
    }
    public static function url(?string $url): ?string {
        if(!$url)return null;
        $p=parse_url($url);
        if(!$p||empty($p['host'])||!in_array(strtolower($p['scheme']??''),['http','https'],true))return '(invalid URL)';
        // Research logs intentionally omit userinfo, query strings and fragments.
        return mb_substr(strtolower($p['scheme']).'://'.strtolower($p['host']).(isset($p['port'])?':'.$p['port']:'').($p['path']??'/'),0,2048);
    }
    public static function registerProfile(int $user,string $role): void {
        if(!isset(self::ROLES[$role]))throw new \InvalidArgumentException('Choose a valid professional role.');
        $c=self::client($_SERVER);
        $q=\db()->prepare('INSERT INTO user_profiles(user_id,professional_role,registration_ip,user_agent,os_name,browser_name,device_type,language) VALUES (?,?,?,?,?,?,?,?)');
        $q->execute(array_merge([$user,$role],array_values($c)));
    }
    public static function auditContext(string $id): void {
        $q=\db()->prepare('INSERT INTO audit_context(audit_id,connection_ip,user_agent,os_name,browser_name,device_type,language) VALUES (?,?,?,?,?,?,?)');
        $q->execute(array_merge([$id],array_values(self::client($_SERVER))));
    }
    public static function record(string $type,?int $user,string $visitor,?string $audit=null,?string $url=null,array $metadata=[]): void {
        if(!in_array($type,self::EVENTS,true))throw new \InvalidArgumentException('Unknown activity type.');
        $q=\db()->prepare('INSERT INTO usage_events(event_type,user_id,visitor_hash,audit_id,page_url,connection_ip,user_agent,os_name,browser_name,device_type,language,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute(array_merge([$type,$user,$visitor,$audit,self::url($url)],array_values(self::client($_SERVER)),[$metadata?json_encode($metadata,JSON_INVALID_UTF8_SUBSTITUTE):null]));
    }
}
