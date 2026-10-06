<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Audit\Research;use Audit\Admin;
$n=0;function researchOk(bool $ok,string $label): void{global $n;if(!$ok)throw new RuntimeException('FAIL: '.$label);$n++;echo 'PASS: '.$label.PHP_EOL;}
$client=Research::client(['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>'198.51.100.1','HTTP_USER_AGENT'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36 Edg/130.0','HTTP_ACCEPT_LANGUAGE'=>'en-GB,en;q=0.9']);
researchOk($client['connection_ip']==='127.0.0.1','Forwarding headers cannot spoof stored connection IP');
researchOk($client['os_name']==='Windows'&&$client['browser_name']==='Edge'&&$client['device_type']==='Desktop','Edge prioritised over Chrome token; Windows version not invented');
researchOk($client['language']==='en-GB','Primary language captured');
$client=Research::client(['REMOTE_ADDR'=>'invalid','HTTP_USER_AGENT'=>'Mozilla/5.0 (Linux; Android 14; Mobile) Chrome/130.0']);researchOk($client['connection_ip']===null&&$client['os_name']==='Android'&&$client['device_type']==='Mobile','Invalid IP rejected and Android mobile identified');
$client=Research::client(['HTTP_USER_AGENT'=>'Mozilla/5.0 (iPad; CPU OS 17) Version/17.0 Safari/605.1']);researchOk($client['os_name']==='iOS / iPadOS'&&$client['device_type']==='Tablet'&&$client['browser_name']==='Safari','iPad and Safari estimates');
$client=Research::client(['HTTP_USER_AGENT'=>'CrawlerBot/1.0']);researchOk($client['device_type']==='Bot / automation','Bot-like user agents are labelled estimates');
researchOk(Research::client([])['os_name']===null,'Missing historical client is not fabricated');
$invalid=Research::client(['HTTP_USER_AGENT'=>"\xFFMozilla",'HTTP_ACCEPT_LANGUAGE'=>"\xFFen"]);researchOk(mb_check_encoding($invalid['user_agent'],'UTF-8')&&mb_check_encoding($invalid['language'],'UTF-8'),'Malformed client header bytes normalised before database storage');
$client=Research::client(['HTTP_USER_AGENT'=>str_repeat('a',800)."\r\n"]);researchOk(mb_strlen($client['user_agent'])===512&&strpos($client['user_agent'],"\n")===false,'User-agent bounded and control characters removed');
researchOk(Research::url('https://user:secret@example.test/path?q=token#private')==='https://example.test/path','Research URL strips credentials, queries and fragments');
researchOk(Research::url('javascript:alert(1)')==='(invalid URL)','Non-web URL not stored as a research page');
researchOk(Research::url('https://[::1]:443/')==='https://[::1]:443/','IPv6 URL parsing preserved');
researchOk(!isset(Research::ROLES['super_admin'])&&isset(Research::ROLES['ceo'],Research::ROLES['agency']),'Professional roles do not contain administrative privileges');
$f=Admin::filters(['from'=>'2024-02-29','to'=>'2024-02-29']);researchOk($f['end']==='2024-03-01 00:00:00','UTC inclusive date interval handles leap day');
foreach([['from'=>'2026-02-30'],['from'=>'2026-02-01','to'=>'2026-01-01'],['view'=>'secrets'],['q'=>[]],['q'=>str_repeat('a',151)],['role'=>'super_admin'],['state'=>'other'],['before'=>'1 OR 1=1']] as $bad){$rejected=false;try{Admin::filters($bad);}catch(InvalidArgumentException $e){$rejected=true;}researchOk($rejected,'Reject invalid admin filter '.json_encode($bad));}
foreach(['=1+1','+SUM(A1)','-1+2','@SUM(A1)',"\t=1+1",'  =1+1'] as $cell)researchOk(substr(Admin::csvCell($cell),0,1)==="'",'Neutralise spreadsheet formulas');
researchOk(Admin::csvCell('https://example.test/')==='https://example.test/','Ordinary CSV text unchanged');
$csv=Admin::csv([['id'=>1,'email'=>'normal@example.test','created_at'=>'2026-10-06 10:20:30','password_hash'=>'DO-NOT-EXPORT']], 'users');researchOk(strpos($csv,'2026-10-06T10:20:30Z')!==false&&strpos($csv,'DO-NOT-EXPORT')===false&&strpos($csv,'password_hash')===false,'CSV has explicit UTC timestamps and an allowlist excluding password hashes');
echo "\n$n research unit checks passed.\n";
