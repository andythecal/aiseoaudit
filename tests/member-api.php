<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if(app_config('environment')!=='local')exit;
$cookie=tempnam(app_config('storage_path'),'qa-member-cookie-');$other=tempnam(app_config('storage_path'),'qa-member-cookie-');
$email='qa-member-api-'.bin2hex(random_bytes(8)).'@example.test';$password=bin2hex(random_bytes(16));$userId=null;$ids=[];$assertions=0;
function verify($condition,$label){global $assertions;if(!$condition)throw new RuntimeException('FAIL: '.$label);$assertions++;echo 'PASS: '.$label."\n";}
function req($action,$data=null,$csrf='',$outsider=false){
 global $cookie,$other;$ch=curl_init('http://aiseoauditor.test/api.php?action='.$action);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$outsider?$other:$cookie,CURLOPT_COOKIEJAR=>$outsider?$other:$cookie,CURLOPT_TIMEOUT=>65]);
 if($data!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$csrf]]);
 $body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=curl_error($ch);curl_close($ch);
 if($body===false)throw new RuntimeException($error);
 return [$code,json_decode($body,true),$body,$type];
}
try{
 [$status,$session]=req('session');$csrf=$session['csrf'];verify($session['allowance']['basic']['limit']===5&&$session['allowance']['deep']['limit']===0,'Guest allowance exposed accurately');
 $visitor='';
 foreach(file($cookie) as $line){$parts=explode("\t",trim($line));if(count($parts)>=7&&$parts[5]==='audit_visitor')$visitor=hash('sha256',$parts[6]);}
 verify($visitor!=='','Signed visitor cookie established');
 $id=bin2hex(random_bytes(16));$ids[]=$id;
 $r=(new Audit\Engine())->analyze(['url'=>'https://example.com/','body'=>'<html><head><title>Test</title></head><body><h1>Test page</h1><p>Useful sample.</p></body></html>','status'=>200,'headers'=>[]]);
 $q=db()->prepare("INSERT INTO audits(id,visitor_hash,requested_url,final_url,state,score,result_json,engine_version) VALUES (?,?,'https://example.com/','https://example.com/','complete',?,?,?)");$q->execute([$id,$visitor,$r['score'],json_encode($r),Audit\Engine::VERSION]);
 [$status,$guest]=req('report&id='.$id);verify($status===200&&count($guest['report']['checks'])>20&&!$guest['report']['locked'],'Existing basic detail remains free');
 verify($guest['report']['accessibility']['status']==='fail'&&$guest['report']['accessibility']['detailsLocked']&&!isset($guest['report']['accessibility']['findings']),'Guest accessibility result contains summary only');
 [$status,$export]=req('export&id='.$id);verify($status===200&&$export['report']['accessibility']['detailsLocked']&&!isset($export['report']['accessibility']['findings'],$export['report']['accessibility']['counts'],$export['report']['accessibility']['manualChecks']),'Guest JSON export does not leak member accessibility evidence');
 [$status,$denied]=req('pdf&id='.$id);verify($status===401&&$denied['code']==='registration_required','PDF endpoint requires registration');
 [$status]=req('deepen',['id'=>$id],$csrf);verify($status===401,'In-depth endpoint requires registration');
 [$status,$auth]=req('register',['email'=>$email,'password'=>$password,'professionalRole'=>'marketing'],$csrf);$userId=(int)$auth['user']['id'];$csrf=$auth['csrf'];
 verify($status===200&&$auth['allowance']['basic']['limit']===100&&$auth['allowance']['deep']['limit']===3,'Registration grants daily member allowances');
 [$status,$member]=req('report&id='.$id);verify($status===200&&!$member['report']['accessibility']['detailsLocked']&&count($member['report']['accessibility']['findings'])>0,'Registration unlocks saved accessibility findings without a rescan');
 [$status,$export]=req('export&id='.$id);verify($status===200&&count($export['report']['accessibility']['findings'])>0,'Member JSON export includes accessibility evidence');
 verify(Audit\Allowance::summary($userId,$visitor)['deep']['used']===0,'Accessibility details do not consume in-depth credit');
 [$status,$json,$bytes,$type]=req('pdf&id='.$id);verify($status===200&&strpos($type,'application/pdf')===0&&substr($bytes,0,5)==='%PDF-','Registered owner receives a generated PDF');
 verify(Audit\Allowance::summary($userId,$visitor)['deep']['used']===0,'PDF download does not use an in-depth credit');
 [$status]=req('pdf&id='.$id,null,'',true);verify($status===404,'Other visitor cannot download PDF');
 [$status,$import]=req('browser-audits',['decision'=>'import'],$csrf);verify($status===200&&$import['imported']===1,'Browser audit imported only after explicit consent');
 [$status,$deep]=req('deepen',['id'=>$id],$csrf);
 verify($status===200&&$deep['report']['advanced']['state']==='complete','Live in-depth audit completes');
 verify(isset($deep['report']['advanced']['result']['images'],$deep['report']['advanced']['result']['sitemap'],$deep['report']['advanced']['result']['guidance']),'In-depth report includes all three extras');
 verify($deep['allowance']['deep']['used']===1,'One in-depth credit consumed');
 [$status,$repeat]=req('deepen',['id'=>$id],$csrf);verify($status===200&&$repeat['allowance']['deep']['used']===1,'Repeated upgrade of saved report consumes no additional credit');
 $q=db()->prepare("INSERT INTO daily_usage(subject_hash,kind,usage_day,used) VALUES (?,'basic',?,99) ON DUPLICATE KEY UPDATE used=99");$q->execute([Audit\Allowance::subject('user:'.$userId),Audit\Allowance::day()]);
 [$status,$last]=req('audit',['url'=>'http://127.0.0.1/'],$csrf);$ids[]=$last['report']['id'];verify($status===201&&$last['allowance']['basic']['remaining']===0,'100th basic attempt allowed and stored');
 [$status,$limit]=req('audit',['url'=>'https://example.com/'],$csrf);verify($status===429&&$limit['code']==='basic_limit','101st basic attempt rejected by API');
 [$status,$logout]=req('logout',[],$csrf);
 [$status]=req('pdf&id='.$id);verify($status===404,'Signing out removes access to account PDF');
 foreach(['vendor/dompdf/autoload.inc.php','database/002-membership.sql'] as $path){$ch=curl_init('http://aiseoauditor.test/'.$path);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);verify(in_array($code,[403,404],true),'Private runtime denied: '.$path);}
 echo "\n$assertions member API checks passed.\n";
}finally{
 require __DIR__.'/research-cleanup.php';
 cleanupResearchFixtures($ids,[$userId],[$cookie,$other]);
 foreach($ids as $id){$q=db()->prepare('DELETE FROM audits WHERE id=?');$q->execute([$id]);}
 if($userId){$q=db()->prepare('DELETE FROM daily_usage WHERE subject_hash=?');$q->execute([Audit\Allowance::subject('user:'.$userId)]);$q=db()->prepare('DELETE FROM users WHERE id=?');$q->execute([$userId]);}
 @unlink($cookie);@unlink($other);
}
