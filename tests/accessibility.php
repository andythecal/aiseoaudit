<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Audit\Accessibility;
use Audit\Engine;
use Audit\Html;
use Audit\Reports;
$n=0;
function verifyA(bool $ok,string $label): void {global $n;if(!$ok)throw new RuntimeException('FAIL: '.$label);$n++;echo 'PASS: '.$label.PHP_EOL;}
function scanA(string $body,string $attrs='lang="en"',string $head='<title>Example</title>'): array {return (new Accessibility())->analyze(new Html('<html '.$attrs.'><head>'.$head.'</head><body>'.$body.'</body></html>'));}
function ruleA(array $r,string $rule): array {return array_values(array_filter($r['findings'],function($f)use($rule){return $f['rule']===$rule;}));}
$r=scanA('<main><h1>Welcome</h1><nav><a href="/">Home</a></nav><p>Useful information</p><label for="q">Search</label><input id="q"><button>Find</button><img alt=""><iframe title="Office map"></iframe></main>');
verifyA($r['status']==='pass'&&$r['counts']===['fail'=>0,'warn'=>0],'Native HTML, labelled controls and decorative images pass');
$r=scanA('<div role="main"><h1>Welcome</h1><div role="navigation" aria-label="Primary"><a href="/">Home</a></div></div>');verifyA(!ruleA($r,'main-landmark')&&$r['status']==='pass','Equivalent main/navigation roles accepted without redundant native markup');
$r=scanA('<main><p>Hello</p><nav><a href="/">Home</a></nav><nav><a href="/help">Help</a></nav></main>');verifyA(count(ruleA($r,'navigation-label'))===2&&$r['status']==='pass','Navigation naming issues are reviews, not false failures');
$r=scanA('<main><nav aria-label="Primary"><a href="/">Home</a></nav><nav aria-label="Primary"><a href="/">Home</a></nav></main>');verifyA(!ruleA($r,'navigation-label'),'Repeated identical navigation may share a name');
$r=scanA('<main><input placeholder="Email"><button><svg></svg></button><a href="/"><img alt=""></a></main>');verifyA(count(ruleA($r,'form-label'))===1&&count(ruleA($r,'control-name'))===2&&$r['status']==='fail','Placeholder-only fields and unnamed icon controls fail');
$r=scanA('<main><label>Email<input></label><button aria-label="Close"><svg></svg></button><a href="/"><img alt="Home"></a><button><svg><title>Search</title></svg></button></main>');verifyA(!ruleA($r,'form-label')&&!ruleA($r,'control-name'),'Wrapping labels, ARIA names, image alt and SVG title name controls');
$r=scanA('<main><span id="q" hidden>Search</span><input aria-labelledby="q"><label for="button">Send</label><button id="button"></button></main>');verifyA(!ruleA($r,'form-label')&&!ruleA($r,'control-name'),'Explicitly referenced hidden labels and native button labels accepted');
$r=scanA('<main><input title="Email"><input type="submit"><input type="reset"><input type="image" alt="Search"></main>');verifyA(!ruleA($r,'form-label')&&!ruleA($r,'control-name'),'Native title and default submit/reset names accepted');
$r=scanA('<main><span id="empty"></span><input aria-label="Fallback" aria-labelledby="empty"></main>');verifyA(count(ruleA($r,'form-label'))===1,'Resolved empty labelledby takes precedence over aria-label');
$r=scanA('<main><input hidden><input type="hidden"><div hidden><button></button></div><div inert><button></button></div><div style="display:none"><input></div><p>Hello</p></main>');verifyA(!$r['counts']['fail'],'Hidden, inert and inline-hidden content does not produce missing-name failures');
$r=scanA('<main><div aria-hidden="true"><button>Hidden</button></div><p>Hello</p></main>');verifyA(count(ruleA($r,'hidden-focus'))===1&&$r['status']==='pass','aria-hidden focus is a manual review, not asserted browser behaviour');
$r=scanA('<main><div aria-hidden="true" inert><button>Hidden</button></div><p>Hello</p></main>');verifyA(!ruleA($r,'hidden-focus'),'Inert content is excluded from hidden-focus warnings');
$r=scanA('<main><img src="x"><img alt=""><img role="presentation"><img aria-label="Chart"></main>');verifyA(count(ruleA($r,'image-alt'))===1,'Only missing image alternatives fail; intentional decorations accepted');
$r=scanA('<main><iframe></iframe><iframe title="Map"></iframe><iframe aria-label="Video"></iframe></main>');verifyA(count(ruleA($r,'frame-name'))===1,'Frames require a name, including valid ARIA alternatives');
$r=scanA('<main><input aria-labelledby="missing"><span id="d">One</span><span id="d">Two</span><button aria-describedby="d">Send</button><button aria-controls="dynamic">Open</button></main>');
$refs=ruleA($r,'aria-reference');verifyA(count($refs)===3&&$refs[0]['status']==='fail'&&$refs[2]['status']==='warn','Missing/ambiguous naming refs fail; dynamic controls references need review');
$r=scanA('<main><button aria-expanded="maybe">Menu</button><button aria-pressed="mixed">Format</button><div role="heading" aria-level="0">Title</div></main>');verifyA(count(ruleA($r,'aria-value'))===1&&count(ruleA($r,'aria-number'))===1,'Invalid ARIA enums/numbers fail while valid mixed state is accepted');
$r=scanA('<main><table aria-rowcount="0" aria-colcount="0"><tr><td aria-rowspan="0">Item</td></tr></table><div aria-setsize="-1">Item</div></main>');verifyA(!ruleA($r,'aria-number'),'Zero table counts and rowspan, and unknown set size are valid');
$r=scanA('<main><input type="checkbox" role="checkbox" aria-label="Accept"><div role="checkbox" aria-label="Accept"></div><h2 role="heading">Title</h2></main>');verifyA(count(ruleA($r,'aria-required'))===1,'Native control states and native heading levels are not unnecessarily required as ARIA');
$r=scanA('<main><input role="combobox" aria-label="Search" aria-expanded="false"><input role="spinbutton" aria-label="Quantity"><input role="combobox" aria-label="Search" aria-expanded="true"></main>');verifyA(count(ruleA($r,'aria-required'))===1,'Closed combobox popup may be absent; empty spinbutton may omit valuenow');
$r=scanA('<main><div role="future button">Open</div><div role="widget">Unknown</div><button aria-labl="Close">Close</button></main>');verifyA(count(ruleA($r,'aria-role'))===1&&count(ruleA($r,'aria-attribute'))===1,'ARIA role fallbacks supported; unknown roles and attributes reviewed');
$r=scanA('<main><div role="textbox">Placeholder</div></main>');verifyA(count(ruleA($r,'control-name'))===1,'Custom textbox content is not mistaken for its accessible label');
$r=scanA('<main><h1>Heading</h1><h3>Subsection</h3><h2></h2><table><tr><td>Item</td></tr></table><div onclick="x()">Open</div><a href="/" tabindex="2">Home</a><a href="#missing">Skip to content</a></main>');
foreach(['heading-order','empty-heading','table-headers','custom-control','tab-order','skip-target'] as $rule)verifyA(count(ruleA($r,$rule))===1,'Detect '.$rule);
$r=scanA('<main><p>Hello</p></main>','', '');verifyA(count(ruleA($r,'document-title'))===1&&count(ruleA($r,'document-language'))===1,'Missing document title and language fail');
$r=scanA('<script>render()</script>');verifyA($r['status']==='not_checked','Script-only shell never receives a passing result');
$r=scanA('<main>'.str_repeat('<span>Text</span>',5010).'</main>');verifyA($r['status']==='not_checked'&&$r['truncated'],'Element limit never produces a false complete pass');
$r=scanA('<main>'.str_repeat('<button></button>',170).'</main>');verifyA(count($r['findings'])===150&&$r['counts']['fail']===170&&$r['truncated'],'Findings capped while counts remain accurate');
$r=scanA('<main><input type="password" value="secret"><button id="&lt;script&gt;" aria-describedby="&lt;script&gt;">Name</button></main>');verifyA(strpos(json_encode($r),'secret')===false,'Evidence does not copy field values');
$guest=Reports::project(['accessibility'=>$r],false);$member=Reports::project(['accessibility'=>$r],true);
verifyA($guest['accessibility']['detailsLocked']&&!isset($guest['accessibility']['findings'],$guest['accessibility']['counts'],$guest['accessibility']['manualChecks']),'Guest projection allowlists summary fields only');
verifyA(!$member['accessibility']['detailsLocked']&&$member['accessibility']['findings']===$r['findings'],'Member projection includes saved evidence');
verifyA(Reports::project([],false)['accessibility']['status']==='not_checked','Legacy reports are not falsely passed or rescanned');
verifyA(Engine::failure('https://example.com/','offline')['accessibility']['status']==='not_checked','Failed fetch is not checked');
$page=['url'=>'https://example.com/','body'=>'<html><head><title>Example</title></head><body><h1>Hello</h1><p>Text.</p><button></button></body></html>','status'=>200,'headers'=>[]];
$r=(new Engine())->analyze($page);$savedScore=$r['score'];unset($r['accessibility']);$r=Engine::finalize($r);verifyA($r['score']===$savedScore,'Accessibility findings do not alter SEO scoring');
echo "\n$n accessibility checks passed.\n";
