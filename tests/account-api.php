<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if(app_config('environment')!=='local')exit;
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$users=[];$ids=[];$cookies=[];$count=0;
function checkAccount(bool $ok,string $label): void {global $count;if(!$ok)throw new RuntimeException('FAIL: '.$label);$count++;echo 'PASS: '.$label.PHP_EOL;}
function clientAccount(): string {global $cookies;$p=tempnam(app_config('storage_path'),'qa-account-');$cookies[]=$p;return $p;}
function requestAccount(string $cookie,string $action,?array $body=null,string $csrf=''): array {
    $ch=curl_init('http://aiseoauditor.test/api.php?action='.$action);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30,CURLOPT_PROXY=>'']);
    if($body!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$csrf]]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($raw===false)throw new RuntimeException($error);
    return [$status,json_decode($raw,true)];
}
function visitorAccount(string $cookie): string {
    foreach(file($cookie) as $line){$parts=explode("\t",trim($line));if(count($parts)>=7&&$parts[5]==='audit_visitor')return hash('sha256',$parts[6]);}
    throw new RuntimeException('Missing visitor cookie');
}
function seedAccount(string $visitor,?int $user,string $url='https://example.com/old/',string $state='complete'): string {
    global $ids;$id=bin2hex(random_bytes(16));$ids[]=$id;
    $r=(new Audit\Engine())->analyze(['url'=>$url,'body'=>'<html lang="en"><head><title>History test</title></head><body><main><h1>History test</h1><p>Saved evidence.</p></main></body></html>','status'=>200,'headers'=>[]]);
    $q=db()->prepare("INSERT INTO audits(id,visitor_hash,user_id,requested_url,final_url,state,score,result_json,engine_version,created_at,completed_at) VALUES (?,?,?,?,?,?,?,?,?,'2026-01-01 12:00:00','2026-01-01 12:00:05')");
    $q->execute([$id,$visitor,$user,$url,$url,$state,$r['score'],json_encode($r),Audit\Engine::VERSION]);return $id;
}
function ownerAccount(string $id) { $q=db()->prepare('SELECT user_id FROM audits WHERE id=?');$q->execute([$id]);return $q->fetchColumn(); }
try {
    $a=clientAccount();$device=clientAccount();$b=clientAccount();
    [, $sa]=requestAccount($a,'session');[, $sd]=requestAccount($device,'session');[, $sb]=requestAccount($b,'session');
    $va=visitorAccount($a);$vd=visitorAccount($device);$vb=visitorAccount($b);
    $old=seedAccount($va,null);$old2=seedAccount($va,null,'https://example.com/old-two/');$foreign=seedAccount($vb,null);
    $email='qa-account-'.bin2hex(random_bytes(8)).'@example.test';$password=bin2hex(random_bytes(16));
    [$status,$auth]=requestAccount($a,'register',['email'=>$email,'password'=>$password,'professionalRole'=>'marketing','marketingConsent'=>false],$sa['csrf']);
    checkAccount($status===200,'Registration succeeds');$uid=(int)$auth['user']['id'];$users[]=$uid;$csrf=$auth['csrf'];
    checkAccount($auth['browserAudits']['count']===2&&$auth['browserAudits']['shouldPrompt']&&ownerAccount($old)===null,'Registration asks before claiming any browser audits');
    [, $history]=requestAccount($a,'history');checkAccount($history['total']===0&&$history['scope']==='account','Account history excludes unclaimed browser history');
    [, $report]=requestAccount($a,'report&id='.$old);checkAccount($report['report']['savedTo']==='browser','Browser report is honestly labelled after sign-in');
    [$status,$deep]=requestAccount($a,'deepen',['id'=>$old],$csrf);checkAccount($status===409&&$deep['code']==='import_required','In-depth review requires account ownership without silently importing');
    [$status]=requestAccount($a,'browser-audits');checkAccount($status===405,'Import cannot run through GET');
    [$status]=requestAccount($a,'browser-audits',['decision'=>'import'],'wrong');checkAccount($status===403&&ownerAccount($old)===null,'Import requires valid CSRF');
    [$status]=requestAccount($a,'browser-audits',['decision'=>'anything'],$csrf);checkAccount($status===422,'Import choice validated');
    [$status,$declined]=requestAccount($a,'browser-audits',['decision'=>'decline'],$csrf);
    checkAccount($status===200&&!$declined['browserAudits']['shouldPrompt']&&$declined['browserAudits']['count']===2&&ownerAccount($old)===null,'Decline preserves browser audits and remembers the decision');
    [, $out]=requestAccount($a,'logout',[],$csrf);[, $auth]=requestAccount($a,'login',['email'=>$email,'password'=>$password,'professionalRole'=>'marketing'],$out['csrf']);$csrf=$auth['csrf'];
    checkAccount(!$auth['browserAudits']['shouldPrompt'],'Repeated login does not nag after a decision');
    [, $second]=requestAccount($device,'login',['email'=>$email,'password'=>$password,'professionalRole'=>'marketing'],$sd['csrf']);$deviceCsrf=$second['csrf'];
    [$status]=requestAccount($device,'report&id='.$old);checkAccount($status===404,'Declined browser audits do not appear on another device');
    [, $before]=requestAccount($a,'session');
    [$status,$import]=requestAccount($a,'browser-audits',['decision'=>'import'],$csrf);
    checkAccount($status===200&&$import['imported']===2&&(int)ownerAccount($old)===$uid&&ownerAccount($foreign)===null,'Explicit later import claims only this browser unowned audits');
    [, $after]=requestAccount($a,'session');checkAccount($before['allowance']===$after['allowance'],'Import uses no audit credits');
    [, $again]=requestAccount($a,'browser-audits',['decision'=>'import'],$csrf);checkAccount($again['imported']===0,'Repeated import is idempotent');
    [, $cross]=requestAccount($device,'report&id='.$old);checkAccount($cross['report']['savedTo']==='account'&&$cross['report']['createdAt']==='2026-01-01 12:00:00Z'&&$cross['report']['finalUrl']==='https://example.com/old/','Second device sees imported report with original date and findings');
    [, $crossHistory]=requestAccount($device,'history');checkAccount($crossHistory['total']===2,'Second device has account-wide history');
    $later=seedAccount($va,null,'https://example.com/browser-only/');
    [, $account]=requestAccount($a,'account');checkAccount($account['account']['counts']['total']===2&&$account['account']['browserAudits']['count']===1&&!$account['account']['browserAudits']['shouldPrompt'],'My Account distinguishes totals from later browser audits');
    [, $browser]=requestAccount($a,'history&scope=browser');checkAccount(count($browser['audits'])===1&&$browser['audits'][0]['id']===$later,'Browser history excludes audits already owned by an account');
    $bemail='qa-other-account-'.bin2hex(random_bytes(8)).'@example.test';
    [, $bauth]=requestAccount($b,'register',['email'=>$bemail,'password'=>$password,'professionalRole'=>'marketing'],$sb['csrf']);$buid=(int)$bauth['user']['id'];$users[]=$buid;
    [, $bimport]=requestAccount($b,'browser-audits',['decision'=>'import','visitor_hash'=>$va],$bauth['csrf']);
    checkAccount($bimport['imported']===1&&(int)ownerAccount($foreign)===$buid&&ownerAccount($later)===null,'Supplied visitor hash cannot target another browser');
    foreach(['report','export','pdf'] as $action){[$status]=requestAccount($b,$action.'&id='.$old);checkAccount($status===404,'Other account cannot access '.$action);}
    [$status]=requestAccount($b,'delete',['id'=>$old],$bauth['csrf']);checkAccount($status===404&&(int)ownerAccount($old)===$uid,'Other account cannot delete owned history');
    for($i=0;$i<23;$i++)seedAccount($va,$uid,$i===0?'https://example.com/literal%_page/':'https://example.com/page-'.$i.'/',$i===1?'failed':'complete');
    [, $first]=requestAccount($device,'history');[, $next]=requestAccount($device,'history&before='.$first['nextCursor']);
    $all=array_merge(array_column($first['audits'],'id'),array_column($next['audits'],'id'));
    checkAccount(count($first['audits'])===20&&count($all)===25&&count(array_unique($all))===25&&$next['nextCursor']===null,'Stable cursor pagination handles equal timestamps without gaps or duplicates');
    [, $searched]=requestAccount($a,'history&q='.rawurlencode('%_'));checkAccount($searched['total']===1&&strpos($searched['audits'][0]['requested_url'],'literal%_')!==false,'Search treats SQL wildcard characters literally');
    [, $failed]=requestAccount($a,'history&state=failed');checkAccount($failed['total']===1&&$failed['audits'][0]['state']==='failed','History status filter works');
    [, $foreignCursor]=requestAccount($b,'history&before='.$old);checkAccount(!$foreignCursor['audits'],'Foreign cursor cannot expose another account history');
    [$status]=requestAccount($a,'history&state=other');checkAccount($status===422,'Invalid history filters rejected');
    [$status,$attempt]=requestAccount($a,'audit',['url'=>'http://127.0.0.1/'],$csrf);$ids[]=$attempt['report']['id'];
    checkAccount($status===201&&$attempt['report']['savedTo']==='account'&&(int)ownerAccount($attempt['report']['id'])===$uid,'New signed-in audit attempts are automatically saved to the account');
    [$status]=requestAccount($device,'report&id='.$attempt['report']['id']);checkAccount($status===200,'New account audit is accessible from another device');
    [$status]=requestAccount($a,'delete',['id'=>$old2],$csrf);[, $updated]=requestAccount($device,'account');checkAccount($status===200&&$updated['account']['counts']['total']===25,'Deleting a saved audit updates account totals across devices');
    $newDeviceAudit=seedAccount($vd,null,'https://example.com/new-device/');
    [, $out]=requestAccount($device,'logout',[],$deviceCsrf);[, $returning]=requestAccount($device,'login',['email'=>$email,'password'=>$password,'professionalRole'=>'marketing'],$out['csrf']);
    checkAccount($returning['browserAudits']['shouldPrompt']&&$returning['browserAudits']['count']===1,'First encounter with browser history on another device asks for consent');
    [, $out]=requestAccount($a,'logout',[],$csrf);[$status]=requestAccount($a,'account');checkAccount($status===401,'My Account requires sign-in');
    [$status]=requestAccount($a,'history&scope=account');checkAccount($status===401,'Account history requires sign-in');
    [, $anon]=requestAccount($a,'history');checkAccount($anon['total']===1&&$anon['audits'][0]['id']===$later,'Sign-out leaves only unclaimed browser history visible');
    [$status]=requestAccount($a,'report&id='.$old);checkAccount($status===404,'Sign-out protects formerly imported reports');
    echo "\n$count account API checks passed.\n";
} finally {
 require __DIR__.'/research-cleanup.php';
 cleanupResearchFixtures($ids,$users,$cookies);
    foreach($ids as $id){$q=db()->prepare('DELETE FROM audits WHERE id=?');$q->execute([$id]);}
    foreach($users as $id){$q=db()->prepare('DELETE FROM daily_usage WHERE subject_hash=?');$q->execute([Audit\Allowance::subject('user:'.$id)]);$q=db()->prepare('DELETE FROM users WHERE id=?');$q->execute([$id]);}
    foreach($cookies as $cookie)@unlink($cookie);
}
