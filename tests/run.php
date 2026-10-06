<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Audit\Html;
use Audit\Http;
use Audit\Engine;
use Audit\Auxiliary;
use Audit\Reports;
$count = 0;
function expect($condition, string $message): void { global $count; if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } $count++; echo "PASS: " . $message . "\n"; }
function page(string $html, array $headers = []): array { return ['url'=>'https://example.com/article','body'=>$html,'status'=>200,'headers'=>$headers,'chain'=>[]]; }
function findCheck(array $r, string $id): array { foreach ($r['checks'] as $c) { if ($c['id']===$id) { return $c; } } throw new RuntimeException('Missing check: ' . $id); }
expect((new Html('<html><body><nav>one two three</nav><script>lots of fake words</script><h1>Hello</h1><p>See our <a>guide</a> for <strong>more</strong>.</p><footer>not counted</footer></body></html>'))->wordCount() === 6,'Word count includes inline descendants exactly once and excludes non-selected boilerplate');
expect((new Html('<p>One&nbsp;two' . "\n\t" . 'three</p>'))->wordCount() === 3,'Unicode and source whitespace collapse');
expect((new Html('<div>Not paragraph content</div>'))->wordCount() === 0,'No paragraphs or headings means zero words');
expect((new Html('<footer><p>These footer words count</p></footer>'))->wordCount() === 4,'Exact algorithm includes selected tags even inside footer');
$engine = new Engine();
foreach ([40=>'fail',100=>'warn',250=>'warn',300=>'pass',400=>'pass'] as $words=>$status) {
    $r = $engine->analyze(page('<p>' . implode(' ',array_fill(0,$words,'word')) . '</p>'));
    expect($r['wordCount'] === $words && findCheck($r,'word-count')['status'] === $status,'Word count threshold: ' . $words);
}
$r = $engine->analyze(page('<h1>Main</h1><h3>Skip</h3><h2>Back</h2><h3>Allowed</h3>'));
expect(count($r['headingIssues']) === 1,'Heading rule flags increases by more than one only');
expect(findCheck($engine->analyze(page('<h2>First</h2>')),'hierarchy')['status'] === 'fail','First heading must be H1');
$r = $engine->analyze(page('<h1>Title</h1>', ['x-robots-tag'=>['noindex']]));
expect(findCheck($r,'indexable')['status'] === 'fail','HTTP noindex is checked');
$r = $engine->analyze(page('<html><head><meta name="robots" content="index"><meta name="robots" content="NOINDEX"></head><body></body></html>'));
expect(findCheck($r,'indexable')['status'] === 'fail','Duplicate robots directives cannot hide noindex');
$r = $engine->analyze(page('<script type="application/ld+json">{oops}</script>'));
expect(findCheck($r,'schema')['status'] === 'fail','Invalid JSON-LD fails syntax check');
$r = $engine->analyze(page('<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Article","author":{"name":"A"}}]}</script>'));
expect(findCheck($r,'schema')['status'] === 'pass' && findCheck($r,'geo-author')['status'] === 'pass','JSON-LD graph types and authors are detected');
$robots = "User-agent: *\nDisallow: /private\nAllow: /private/public\nUser-agent: Googlebot\nDisallow: /google\nSitemap: https://example.com/map.xml";
expect(!Auxiliary::robots($robots,'/private/a')['allowed'],'Robots disallow path');
expect(Auxiliary::robots($robots,'/private/public/a')['allowed'],'Longest robots allow wins');
expect(Auxiliary::robots($robots,'/private/a','googlebot')['allowed'],'Specific bot group overrides wildcard group');
expect(!Auxiliary::robots($robots,'/google','googlebot')['allowed'],'Specific bot rule blocks its path');
expect(!Auxiliary::robots("User-agent: *\nDisallow: /*?secret=$",'/x?secret=')['allowed'],'Robots wildcard and terminal anchor work');
expect(Auxiliary::robots("User-agent: *\nDisallow: /x\nAllow: /x",'/x')['allowed'],'Allow wins equal-length rule tie');
expect(Auxiliary::sitemap('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/</loc></url></urlset>')['valid'],'Namespaced sitemap parsed');
expect(!Auxiliary::sitemap('<urlset><url><loc>https://example.com/</urlset>')['valid'],'Malformed XML rejected');
expect(!Auxiliary::sitemap('<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset/>')['valid'],'XML external entity declarations rejected');
foreach (['127.0.0.1','10.2.3.4','169.254.169.254','100.64.1.1','192.0.2.1','224.0.0.1','::1','::ffff:127.0.0.1','fc00::1','2001:db8::1','2001:0db8::1','2001::1','2002:7f00:1::'] as $ip) { expect(!Http::publicIp($ip),'Reject non-public IP ' . $ip); }
expect(Http::publicIp('93.184.216.34'),'Public IPv4 accepted');
expect(Http::resolve('https://example.com/a/b?q=1','../c?x=2') === 'https://example.com/c?x=2','Relative redirects resolve correctly');
foreach (['http://user:pass@example.com','https://example.com:444/','file:///etc/passwd',"https://example.com/\r\nX:1"] as $url) {
    $rejected=false;try{Http::normalize($url);}catch(InvalidArgumentException $e){$rejected=true;}expect($rejected,'Reject unsafe URL syntax');
}
$r = Engine::finalize(['checks'=>[Engine::check('a','Meta Tags','A','pass',''),Engine::check('b','Meta Tags','B','warn',''),Engine::check('c','GEO signals','C','pass','')],'incomplete'=>false]);
expect($r['score']===75 && $r['geoScore']===100,'Warnings earn half credit; GEO does not inflate SEO score');
$config['gate_details']=true;
$r['checks']=[['detail'=>'secret finding']];$r['blocks']=[['text'=>'private snippet']];$r['headings']=[1];$r['metadata']=['x'=>1];
$projected = Reports::project($r,false);
expect(!$projected['locked'] && isset($projected['checks'],$projected['blocks'],$projected['headings'],$projected['metadata']),'Basic content stays available even if legacy gate_details config is enabled');
expect(isset(Reports::project($r,true)['checks']),'Account receives full report');
expect(Engine::failure('https://example.com','error')['score']===null,'Failed fetch has no misleading zero SEO score');
foreach (['noindex, nofollow','none','NOINDEX, NOFOLLOW'] as $directive) {
    $r = $engine->analyze(page('<html><head><meta name="robots" content="' . $directive . '"></head><body><h1>Page</h1></body></html>'));
    expect(findCheck($r,'indexable')['status']==='fail' && findCheck($r,'followable')['status']==='fail','Both indexing and following blocked by ' . $directive);
    expect($r['score']<=20 && $r['scoreCap']===20 && $r['crawlabilityUrgent'],'Noindex caps the score and marks crawlability urgent');
}
$r = $engine->analyze(page('<h1>Page</h1>', ['x-robots-tag'=>['nofollow']]));
expect(findCheck($r,'followable')['status']==='fail' && findCheck($r,'indexable')['status']==='pass','HTTP nofollow is separate from noindex');
expect($r['score']<=40 && $r['scoreCap']===40,'Standalone nofollow has a major-failure cap');
$r = Engine::finalize(['checks'=>[Engine::check('a','Technical','A','pass',''),Engine::check('sitemap-xml','Crawlability','Sitemap','fail','')],'incomplete'=>false]);
expect($r['score']===17 && $r['crawlabilityUrgent'],'A crawlability failure has five times the weight of a normal pass');
$checks = [];
for ($i=0;$i<20;$i++) { $checks[]=Engine::check('normal'.$i,'Technical','Normal','pass',''); }
foreach (['indexable','followable','robots-txt','sitemap-xml'] as $id) { $checks[]=Engine::check($id,'Crawlability',$id,'fail',''); }
$r=Engine::finalize(['checks'=>$checks,'incomplete'=>false]);
expect($r['score']===20 && $r['scoreCap']===20,'Four crawlability failures cannot be outweighed by twenty normal passes');
$r=Engine::finalize(['checks'=>[Engine::check('robots-txt','Crawlability','Robots','warn','')],'incomplete'=>false]);
expect($r['score']===50 && !$r['crawlabilityUrgent'] && $r['scoreCap']===null,'Unconfirmed crawlability warnings do not trigger urgent failure caps');
foreach ([0,9] as $words) {
    $r = $engine->analyze(page('<html><body><p>' . implode(' ',array_fill(0,$words,'word')) . '</p><script>lots of script content is not readable content</script></body></html>'));
    $check = findCheck($r,'content-accessibility');
    expect($check['category']==='Crawlability' && $check['status']==='fail' && $r['crawlabilityUrgent'],'Sparse initial HTML without headings is a crawlability issue at ' . $words . ' words');
    expect(strpos($check['recommendation'],'does not prove')!==false && strpos($check['recommendation'],'SEO/GEO')!==false,'Sparse-content advice explains the risk without claiming indexing is impossible');
}
foreach (['<p>one two three four five six seven eight nine ten</p>','<h1>Title</h1>','<h2>Subheading</h2>'] as $html) {
    $r=$engine->analyze(page($html));
    expect(!in_array('content-accessibility',array_column($r['checks'],'id'),true),'Sparse-content trigger requires both fewer than ten words and no headings');
}
$r=$engine->analyze(page('<div>' . str_repeat('Readable text ',100) . '</div>'));
expect(strpos(findCheck($r,'content-accessibility')['detail'],'text in other elements')!==false,'Non-semantic body text is not misrepresented as an empty page');
require __DIR__ . '/slash-fixtures.php';
echo "\n" . $count . " checks passed.\n";
