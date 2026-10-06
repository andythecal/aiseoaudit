<?php
// Additional deterministic network-result fixtures for trailing-slash decisions.
class FixtureHttp extends Audit\Http {
    private $response;
    public function __construct(array $response) { $this->response=$response; }
    public function get(string $url, bool $follow=true): array { return $this->response; }
}
$body='<html><head><title>Example</title></head><body><h1>Article</h1><p>'.str_repeat('word ',150).'</p></body></html>';
$base=['url'=>'https://example.com/article','body'=>$body,'status'=>200,'headers'=>[],'type'=>'text/html'];
foreach ([301=>'pass',308=>'pass',302=>'warn',307=>'warn'] as $code=>$expected) {
    $variant=['status'=>$code,'headers'=>['location'=>['https://example.com/article']],'body'=>'','type'=>'text/html'];
    expect($engine->slash(new FixtureHttp($variant),$base)['status']===$expected,'Slash redirect HTTP '.$code);
}
$variant=['status'=>200,'headers'=>[],'body'=>$body,'type'=>'text/html'];
expect($engine->slash(new FixtureHttp($variant),$base)['status']==='fail','Identical full pages on both slash forms flag duplication');
$canonical='<link rel="canonical" href="https://example.com/article">';
$base['body']=str_replace('</head>',$canonical.'</head>',$body);$variant['body']=$base['body'];
expect($engine->slash(new FixtureHttp($variant),$base)['status']==='warn','Shared canonical softens duplicate warning');
$base['body']='<h1>Short shell</h1>';$variant['body']=$base['body'];
expect($engine->slash(new FixtureHttp($variant),$base)['status']==='warn','Short JS shells do not establish duplication');
foreach ([404=>'pass',410=>'pass',403=>'warn',429=>'warn',500=>'warn'] as $code=>$expected) {
    $variant['status']=$code;
    expect($engine->slash(new FixtureHttp($variant),$base)['status']===$expected,'Slash alternate HTTP '.$code);
}

$fixture = ['url'=>'https://example.com/sitemap.xml','status'=>200,'headers'=>[],'body'=>'<urlset><broken>','type'=>'application/xml'];
[$robots,$map] = Audit\Auxiliary::inspect(new FixtureHttp($fixture),'https://example.com/');
expect($map['statusLabel']==='fail','Reachable malformed sitemap is a failure, not a warning');
$fixture['body']='';
[$robots,$map] = Audit\Auxiliary::inspect(new FixtureHttp($fixture),'https://example.com/');
expect($map['statusLabel']==='fail','Reachable empty sitemap is a failure');
$fixture['status']=404;
[$robots,$map] = Audit\Auxiliary::inspect(new FixtureHttp($fixture),'https://example.com/');
expect($map['statusLabel']==='warn','Missing optional sitemap remains a warning');
