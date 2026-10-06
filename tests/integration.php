<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
if (app_config('environment') !== 'local') { exit("Integration tests only run in the local environment.\n"); }
$base = 'http://aiseoauditor.test/';
$cookie = tempnam(app_config('storage_path'),'qa-cookie-');
$otherCookie = tempnam(app_config('storage_path'),'qa-cookie-');
$ids = []; $email = 'qa-' . bin2hex(random_bytes(8)) . '@example.test'; $password = bin2hex(random_bytes(16)); $userId = null; $assertions = 0;
function check($test,$label) { global $assertions; if (!$test) { throw new RuntimeException('FAIL: ' . $label); } $assertions++; echo 'PASS: ' . $label . "\n"; }
function request($action,$data=null,$token='',$other=false) {
    global $base,$cookie,$otherCookie;
    $ch=curl_init($base . 'api.php?action=' . $action);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$other?$otherCookie:$cookie,CURLOPT_COOKIEJAR=>$other?$otherCookie:$cookie,CURLOPT_TIMEOUT=>65]);
    if ($data!==null) { curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: ' . $token]]); }
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if ($body===false) { throw new RuntimeException($error); }
    $json=json_decode($body,true);if(!is_array($json)) { throw new RuntimeException('Non-JSON response: ' . substr($body,0,500)); }
    return [$status,$json];
}
try {
    [$status,$session]=request('session');$csrf=$session['csrf'];check($status===200,'Session endpoint works through Laragon');
    [$status]=request('audit',['url'=>'https://example.com'],'wrong');check($status===403,'CSRF is required');
    [$status,$data]=request('audit',['url'=>'http://127.0.0.1/'],$csrf);$ids[]=$data['report']['id'];check($status===201 && $data['report']['incomplete'],'SSRF attempt blocked and failed audit saved');
    [$status,$data]=request('audit',['url'=>'https://example.com/'],$csrf);$report=$data['report'];$ids[]=$report['id'];
    check($status===201 && !$report['incomplete'] && count($report['checks'])>=25,'Real public HTML audit completes and saves');
    [$status,$data]=request('report&id=' . $report['id']);check($status===200 && $data['report']['id']===$report['id'],'Owner can reopen saved report');
    request('session',null,'',true);
    [$status]=request('report&id=' . $report['id'],null,'',true);check($status===404,'Another visitor cannot read the report');
    [$status]=request('export&id=' . $report['id'],null,'',true);check($status===404,'Another visitor cannot export report');
    [$status,$data]=request('register',['email'=>$email,'password'=>$password,'professionalRole'=>'marketing','marketingConsent'=>false],$csrf);$csrf=$data['csrf'];$userId=(int)$data['user']['id'];check($status===200,'Account registration works');
    $q=db()->prepare('SELECT COUNT(*) FROM audits WHERE user_id=?');$q->execute([$userId]);check((int)$q->fetchColumn()===0,'Registration does not claim browser audits without consent');
    [$status,$import]=request('browser-audits',['decision'=>'import'],$csrf);check($status===200 && $import['imported']===2,'Explicit import saves browser audits to account');
    $q=db()->prepare('SELECT marketing_consent FROM leads WHERE user_id=?');$q->execute([$userId]);check((int)$q->fetchColumn()===0,'Marketing consent defaults off');
    [$status,$data]=request('logout',[],$csrf);$csrf=$data['csrf'];check($status===200,'Logout works');
    [$status]=request('report&id=' . $report['id']);check($status===404,'Account report no longer accessible after logout on same browser');
    [$status,$data]=request('login',['email'=>$email,'password'=>$password,'professionalRole'=>'marketing'],$csrf);$csrf=$data['csrf'];check($status===200,'Login restores account access');
    [$status,$data]=request('history');check($status===200 && count($data['audits'])===2,'Account history returns saved attempts');
    [$status,$data]=request('export&id=' . $report['id']);check($status===200 && isset($data['report']['checks']),'Owned JSON export includes findings');
    [$status]=request('delete',['id'=>$report['id']],$csrf);check($status===200,'Owner can delete an audit');
    [$status]=request('report&id=' . $report['id']);check($status===404,'Deleted audit cannot be reopened');
    foreach (['config/local.php','storage/php-errors.log','database/schema.sql','src/Engine.php','deploy.php','.gitignore','auditor.md'] as $path) {
        $ch=curl_init($base.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);check(in_array($code,[403,404],true),'Web access denied: '.$path);
    }
    echo "\n".$assertions." integration checks passed.\n";
} finally {
 require __DIR__.'/research-cleanup.php';
 cleanupResearchFixtures($ids,[$userId],[$cookie,$otherCookie]);
    foreach($ids as $id) { $q=db()->prepare('DELETE FROM audits WHERE id=?');$q->execute([$id]); }
    if($userId) { $q=db()->prepare('DELETE FROM users WHERE id=? AND email=?');$q->execute([$userId,$email]); }
    @unlink($cookie);@unlink($otherCookie);
}
