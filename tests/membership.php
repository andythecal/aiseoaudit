<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if(app_config('environment')!=='local')exit("Local test only.\n");
use Audit\Allowance;use Audit\Advanced;use Audit\Guidance;use Audit\Engine;use Audit\Html;
$count=0;
function ok($condition,$label){global $count;if(!$condition)throw new RuntimeException('FAIL: '.$label);$count++;echo 'PASS: '.$label."\n";}
function reserve($kind,$user,$visitor){db()->beginTransaction();$result=Allowance::reserve($kind,$user,$visitor);if($result)db()->commit();else db()->rollBack();return $result;}
$_SERVER['REMOTE_ADDR']='qa-'.bin2hex(random_bytes(8));$visitor=bin2hex(random_bytes(32));$uid=null;$id=bin2hex(random_bytes(16));$subjects=[];
try {
    $subjects=Allowance::subjects(null,$visitor);
    for($i=0;$i<5;$i++)ok(reserve('basic',null,$visitor),'Anonymous allowance slot '.($i+1));
    ok(!reserve('basic',null,$visitor),'Sixth anonymous audit rejected');
    ok(!reserve('basic',null,bin2hex(random_bytes(32))),'Cookie reset cannot bypass daily network allowance');
    $q=db()->prepare('INSERT INTO users (email,password_hash) VALUES (?,?)');$q->execute(['qa-members-'.bin2hex(random_bytes(8)).'@example.test',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);$uid=(int)db()->lastInsertId();
    $subjects=array_merge($subjects,Allowance::subjects($uid,$visitor));
    for($i=0;$i<100;$i++){if(!reserve('basic',$uid,$visitor))throw new RuntimeException('Registered allowance stopped early.');}
    ok(!reserve('basic',$uid,$visitor),'101st registered basic audit rejected');
    for($i=0;$i<3;$i++)ok(reserve('deep',$uid,$visitor),'In-depth credit '.($i+1).' is independent of basic allowance');
    ok(!reserve('deep',$uid,$visitor),'Fourth daily in-depth audit rejected');
    $q=db()->prepare("INSERT INTO audits (id,visitor_hash,user_id,requested_url,state,engine_version) VALUES (?,?,?,'https://example.com/','complete','test')");$q->execute([$id,$visitor,$uid]);
    $q=db()->prepare("INSERT INTO advanced_audits (audit_id,user_id,charged_day) VALUES (?,?,?)");$q->execute([$id,$uid,Allowance::day()]);
    Allowance::failDeep($id,'Fixture failure');
    ok(Allowance::summary($uid,$visitor)['deep']['remaining']===1,'Failed in-depth scan returns one credit');
    Allowance::failDeep($id,'Repeated failure');
    ok(Allowance::summary($uid,$visitor)['deep']['remaining']===1,'Refund is idempotent');
    $q=db()->prepare('DELETE FROM audits WHERE id=?');$q->execute([$id]);
    ok(Allowance::summary($uid,$visitor)['basic']['remaining']===0,'Deleting audit does not restore basic quota');
    $yesterday=(new DateTimeImmutable('yesterday',new DateTimeZone('Europe/London')))->format('Y-m-d');
    $q=db()->prepare("INSERT INTO daily_usage (subject_hash,kind,usage_day,used) VALUES (?,'deep',?,999)");$q->execute([$subjects[count($subjects)-1],$yesterday]);
    ok(Allowance::summary($uid,$visitor)['deep']['remaining']===1,'Previous-day usage does not affect today');
    ok((new DateTimeImmutable(Allowance::resets()))->getTimezone()->getName()!==false,'Reset timestamp has an explicit offset');
    ok(count(Advanced::urlReasons('https://example.com/latest-news/','https://example.com/'))===0,'Test-page heuristic avoids matching latest');
    ok(count(Advanced::urlReasons('https://example.com/test-page/?preview=1','https://example.com/'))===2,'Test-page and query-string risks flagged');
    ok(count(Advanced::urlReasons('http://staging.example.com/','https://example.com/'))===3,'Staging hostname, host mismatch and HTTP flagged');
    $xml=Advanced::xmlEntries('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/sample-page/</loc></url></urlset>');
    ok(count($xml['entries'])===1 && $xml['type']==='urlset','Advanced sitemap namespace parsed');
    $rejected=false;try{Advanced::xmlEntries('<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset/>');}catch(RuntimeException $e){$rejected=true;}
    ok($rejected,'Advanced XML rejects external entities');
    $images=Advanced::images(new Html('<html><body><img src="/a.png"><img src="/b.png" alt=""><img src="/c.png" alt="Useful image"></body></html>'),'https://example.com/');
    ok($images['missing']===1&&$images['empty']===1&&$images['present']===1,'Image inventory distinguishes absent, empty and present alt');
    ok($images['items'][0]['source']==='https://example.com/a.png','Image paths resolve safely');
    $r=(new Engine())->analyze(['url'=>'https://example.com/','status'=>200,'body'=>'<h1>Fixture</h1><p>Example content.</p>','headers'=>[]]);
    $guidance=Guidance::forReport($r);
    ok(count($guidance['checks'])===count($r['checks']),'Every finding has advanced guidance');
    echo "\n$count membership checks passed.\n";
} finally {
    if(db()->inTransaction())db()->rollBack();
    require __DIR__.'/research-cleanup.php';cleanupResearchFixtures([$id],[$uid],[]);
    $q=db()->prepare('DELETE FROM audits WHERE id=?');$q->execute([$id]);
    if($uid){$q=db()->prepare('DELETE FROM users WHERE id=?');$q->execute([$uid]);}
    foreach($subjects as $subject){$q=db()->prepare('DELETE FROM daily_usage WHERE subject_hash=?');$q->execute([$subject]);}
}
