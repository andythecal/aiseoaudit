<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if(app_config('environment')!=='local')exit;
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$users=[];$ids=[];$cookies=[];$n=0;
function adminOk(bool $ok,string $label): void {global $n;if(!$ok)throw new RuntimeException('FAIL: '.$label);$n++;echo 'PASS: '.$label.PHP_EOL;}
function adminClientCookie(): string {global $cookies;$p=tempnam(app_config('storage_path'),'qa-research-');$cookies[]=$p;return $p;}
function adminReq(string $cookie,string $action,?array $data=null,string $csrf=''): array {
    $ch=curl_init('http://aiseoauditor.test/api.php?action='.$action);
    $headers=['Accept-Language: en-GB,en;q=0.9','X-Forwarded-For: 198.51.100.99'];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30,CURLOPT_PROXY=>'',CURLOPT_USERAGENT=>'=1+1 Mozilla/5.0 (Windows NT 10.0; Win64) Chrome/130.0 <script>bad</script>']);
    if($data!==null){$headers[]='Content-Type: application/json';$headers[]='X-CSRF-Token: '.$csrf;curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data)]);}
    curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=curl_error($ch);curl_close($ch);
    if($body===false)throw new RuntimeException($error);return [$status,json_decode($body,true),$body,$type];
}
function researchVisitor(string $cookie): string {foreach(file($cookie) as $line){$v=explode("\t",trim($line));if(count($v)>=7&&$v[5]==='audit_visitor')return hash('sha256',$v[6]);}throw new RuntimeException('No browser token');}
try {
    $guest=adminClientCookie();$member=adminClientCookie();$admin=adminClientCookie();
    [, $gs]=adminReq($guest,'session');[, $ms]=adminReq($member,'session');[, $as]=adminReq($admin,'session');
    $visitor=researchVisitor($member);$password=bin2hex(random_bytes(16));$email='qa-research-'.bin2hex(random_bytes(8)).'@example.test';
    foreach(['admin-data','admin-csv','admin-report&id='.str_repeat('a',32)] as $action){[$status]=adminReq($guest,$action);adminOk($status===401,'Guest denied '.$action);}
    [$status]=adminReq($member,'register',['email'=>$email,'password'=>$password],$ms['csrf']);adminOk($status===422,'Registration requires a professional role');
    [$status]=adminReq($member,'register',['email'=>$email,'password'=>$password,'professionalRole'=>'super_admin'],$ms['csrf']);adminOk($status===422,'Privilege names rejected as professional roles');
    [$status,$auth]=adminReq($member,'register',['email'=>$email,'password'=>$password,'professionalRole'=>'agency','is_super_admin'=>true],$ms['csrf']);
    adminOk($status===200,'Registration with role succeeds');$uid=(int)$auth['user']['id'];$users[]=$uid;$csrf=$auth['csrf'];
    adminOk(!(int)$auth['user']['is_super_admin'],'Registration payload cannot grant super-admin permissions');
    $q=db()->prepare('SELECT * FROM user_profiles WHERE user_id=?');$q->execute([$uid]);$profile=$q->fetch();
    adminOk($profile['professional_role']==='agency'&&$profile['os_name']==='Windows'&&$profile['browser_name']==='Chrome'&&$profile['language']==='en-GB','Role, OS, browser and language persisted');
    adminOk(in_array($profile['registration_ip'],['127.0.0.1','::1'],true)&&$profile['registration_ip']!=='198.51.100.99','Connection IP captured without trusting forwarding headers');
    foreach(['admin-data','admin-csv','admin-report&id='.str_repeat('a',32)] as $action){[$status]=adminReq($member,$action);adminOk($status===403,'Ordinary member denied '.$action);}
    $adminEmail='qa-super-admin-'.bin2hex(random_bytes(8)).'@example.test';
    $q=db()->prepare('INSERT INTO users(email,password_hash) VALUES (?,?)');$q->execute([$adminEmail,password_hash($password,PASSWORD_DEFAULT)]);$aid=(int)db()->lastInsertId();$users[]=$aid;
    $q=db()->prepare('INSERT INTO super_admins(user_id) VALUES (?)');$q->execute([$aid]);
    [$status,$adminAuth]=adminReq($admin,'login',['email'=>$adminEmail,'password'=>$password],$as['csrf']);adminOk($status===200&&(int)$adminAuth['user']['is_super_admin']===1,'Admin login derives privilege from database');
    $id=bin2hex(random_bytes(16));$ids[]=$id;$url='https://example.test/research-page?secret=do-not-log#private';
    $r=(new Audit\Engine())->analyze(['url'=>$url,'body'=>'<html lang="en"><head><title>Research fixture</title></head><body><main><h1>Research</h1><p>Saved evidence.</p></main></body></html>','status'=>200,'headers'=>[]]);
    $q=db()->prepare("INSERT INTO audits(id,visitor_hash,user_id,requested_url,final_url,state,score,result_json,engine_version,completed_at) VALUES (?,?,?,?,?,'complete',?,?,?,UTC_TIMESTAMP())");$q->execute([$id,$visitor,$uid,$url,$url,$r['score'],json_encode($r),Audit\Engine::VERSION]);
    [$status,$result]=adminReq($admin,'admin-report&id='.$id);adminOk($status===200&&$result['report']['id']===$id,'Admin can inspect another user saved report through dedicated read-only endpoint');
    [$status]=adminReq($admin,'report&id='.$id);adminOk($status===404,'Admin does not bypass ownership on ordinary account endpoints');
    [$status]=adminReq($admin,'delete',['id'=>$id],$adminAuth['csrf']);adminOk($status===404,'Research admin cannot delete another account report through member endpoint');
    [, $data]=adminReq($admin,'admin-data&view=users&q='.rawurlencode($email));$user=$data['results']['items'][0]??[];
    adminOk($data['results']['total']===1&&$user['professional_role']==='agency'&&!isset($user['password_hash']),'Admin user research exposes role and client without password hashes');
    adminOk(isset($data['overview']['counts']['events'],$data['overview']['sites'],$data['overview']['roles']),'Period totals, site and role breakdowns available');
    [, $audits]=adminReq($admin,'admin-data&view=audits&q='.rawurlencode($email));$item=$audits['results']['items'][0];
    adminOk($item['page_url']==='https://example.test/research-page'&&$item['connection_ip']===null,'Research audit list redacts query/fragment and marks legacy client data absent');
    [, $legacy]=adminReq($admin,'admin-data&view=users&role=not_recorded&q='.rawurlencode($adminEmail));adminOk($legacy['results']['total']===1&&$legacy['results']['items'][0]['professional_role']===null,'Legacy profile data is not invented');
    [$status,$attempt]=adminReq($member,'audit',['url'=>'http://127.0.0.1/'],$csrf);$ids[]=$attempt['report']['id'];adminOk($status===201&&$attempt['report']['incomplete'],'New failed audit still saved');
    $q=db()->prepare('SELECT * FROM audit_context WHERE audit_id=?');$q->execute([$attempt['report']['id']]);$context=$q->fetch();adminOk($context['os_name']==='Windows'&&$context['connection_ip']===$profile['registration_ip'],'Per-audit client context saved');
    $q=db()->prepare('SELECT event_type,occurred_at FROM usage_events WHERE audit_id=? ORDER BY id');$q->execute([$attempt['report']['id']]);$events=$q->fetchAll();adminOk(array_column($events,'event_type')===['audit_started','audit_failed']&&$events[0]['occurred_at']!==null,'Audit start and failure timestamped without duplicate shutdown event');
    adminReq($member,'report&id='.$id);adminReq($member,'export&id='.$id);
    [$status,$unused,$pdf,$type]=adminReq($member,'pdf&id='.$id);adminOk($status===200&&strpos($type,'application/pdf')===0,'PDF download still works with research recording');
    $q=db()->prepare('SELECT event_type,page_url FROM usage_events WHERE audit_id=? ORDER BY id');$q->execute([$id]);$events=$q->fetchAll();
    adminOk(in_array('report_viewed',array_column($events,'event_type'),true)&&in_array('json_exported',array_column($events,'event_type'),true)&&in_array('pdf_downloaded',array_column($events,'event_type'),true),'Report views and both export types recorded');
    adminOk(strpos(json_encode($events),'do-not-log')===false,'Event URLs do not retain query tokens');
    [$status,$unused,$csv,$type]=adminReq($admin,'admin-csv&view=users&q='.rawurlencode($email));
    adminOk($status===200&&strpos($type,'text/csv')===0&&strpos($csv,"'=1+1")!==false&&strpos($csv,'password_hash')===false,'Admin CSV is authenticated and neutralises formula-like user-agent');
    adminOk((bool)preg_match('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ/',$csv),'CSV timestamps explicitly identify UTC');
    [$status]=adminReq($admin,'admin-data&from=2026-02-30');adminOk($status===422,'Invalid date rejected by API');
    for($i=0;$i<55;$i++)Audit\Research::record('report_viewed',$uid,$visitor,$id,$url);
    [, $page1]=adminReq($admin,'admin-data&view=activity&event=report_viewed&q='.rawurlencode($email));
    [, $page2]=adminReq($admin,'admin-data&view=activity&event=report_viewed&q='.rawurlencode($email).'&before='.$page1['results']['nextCursor']);
    $all=array_merge(array_column($page1['results']['items'],'id'),array_column($page2['results']['items'],'id'));
    adminOk(count($page1['results']['items'])===50&&count($all)===56&&count(array_unique($all))===56,'Activity pagination remains stable for matching timestamped events');
    [$status]=adminReq($member,'delete',['id'=>$id],$csrf);adminOk($status===200,'Report deletion remains available to owner');
    [$status]=adminReq($admin,'admin-report&id='.$id);adminOk($status===404,'Deleted report is no longer available to admin');
    $q=db()->prepare("SELECT COUNT(*) FROM usage_events WHERE audit_id=? AND event_type='audit_deleted'");$q->execute([$id]);adminOk((int)$q->fetchColumn()===1,'Deletion is timestamped in separately retained research log');
    $q=db()->prepare('SELECT metadata_json FROM usage_events WHERE user_id=?');$q->execute([$uid]);adminOk(strpos(json_encode($q->fetchAll()),$password)===false,'Passwords never enter research metadata');
    $q=db()->prepare('DELETE FROM super_admins WHERE user_id=?');$q->execute([$aid]);[$status]=adminReq($admin,'admin-data');adminOk($status===403,'Revoked admin privilege takes effect on the next request');
    echo "\n$n research API checks passed.\n";
} finally {
    require __DIR__.'/research-cleanup.php';cleanupResearchFixtures($ids,$users,$cookies);
    foreach($ids as $id){$q=db()->prepare('DELETE FROM audits WHERE id=?');$q->execute([$id]);}
    foreach($users as $id){$q=db()->prepare('DELETE FROM daily_usage WHERE subject_hash=?');$q->execute([Audit\Allowance::subject('user:'.$id)]);$q=db()->prepare('DELETE FROM users WHERE id=?');$q->execute([$id]);}
    foreach($cookies as $cookie)@unlink($cookie);
}
