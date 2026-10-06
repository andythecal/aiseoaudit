<?php
declare(strict_types=1);
namespace Audit;
final class Engine {
    const VERSION = '1.3.0';
    public static function check(string $id, string $category, string $title, string $status, string $detail, string $recommendation = ''): array { return compact('id','category','title','status','detail','recommendation'); }
    public static function failure(string $url, string $error): array {
        return self::finalize(['url'=>$url,'finalUrl'=>$url,'httpStatus'=>0,'checks'=>[self::check('fetch','Crawlability','Page reachable & returns HTML','fail',$error,'Check the URL and server access. A blocked scanner does not establish poor SEO.')],'headings'=>[],'headingIssues'=>[],'blocks'=>[],'metadata'=>[],'wordCount'=>0,'robotsTxt'=>null,'sitemapXml'=>null,'incomplete'=>true,'accessibility'=>Accessibility::unavailable('The page could not be checked. Run a new audit after resolving the fetch issue.')]);
    }
    public function run(string $url): array {
        $http = new Http(); $page = $http->get($url);
        if ($page['status'] < 200 || $page['status'] >= 300 || stripos($page['type'],'html') === false || $page['body'] === '') {
            $r = self::failure($url,'HTTP ' . $page['status'] . '. Expected a successful, non-empty HTML page.');
            $r['finalUrl'] = $page['url']; $r['httpStatus'] = $page['status']; return $r;
        }
        $result = $this->analyze($page);
        [$result['robotsTxt'],$result['sitemapXml']] = Auxiliary::inspect($http,$page['url']);
        foreach ([['robotsTxt','robots-txt','robots.txt rules'],['sitemapXml','sitemap-xml','XML sitemap']] as $file) {
            $f = $result[$file[0]];
            $result['checks'][] = self::check($file[1],'Crawlability',$file[2],$f['statusLabel'],implode(' ',$f['notes']),$f['statusLabel'] === 'pass' ? '' : 'Check the file and server response. Missing robots.txt alone does not prevent crawling; sitemaps help discovery.');
        }
        try { $result['checks'][] = $this->slash($http,$page); }
        catch (\Throwable $e) { $result['checks'][] = self::check('trailing-slash','Technical','Trailing slash consistency','warn',$e->getMessage(),'Verify the alternate URL manually.'); }
        $result['url'] = $url;
        return self::finalize($result);
    }
    public function analyze(array $page): array {
        $html = new Html($page['body']); $url = $page['url']; $checks = [];
        $add = function ($id,$cat,$title,$status,$detail,$rec = '') use (&$checks) { $checks[] = self::check($id,$cat,$title,$status,$detail,$status === 'pass' ? '' : $rec); };
        $title = $html->text('//head/title'); $description = $html->meta('description'); $canonical = $html->canonical();
        $add('title','Meta Tags','Descriptive page title',$title !== '' ? 'pass' : 'fail',$title !== '' ? $title . ' (' . mb_strlen($title) . ' characters)' : 'No title found.','Write a unique, descriptive title. 50–60 characters is a display guideline, not a ranking rule.');
        $add('description','Meta Tags','Meta description',$description !== '' ? 'pass' : 'fail',$description !== '' ? $description . ' (' . mb_strlen($description) . ' characters)' : 'No meta description found.','Add a useful, unique summary. Search engines may choose another snippet.');
        $canonicalStatus = 'fail'; $resolvedCanonical = '';
        if ($canonical !== '') { try { $resolvedCanonical = Http::normalize(Http::resolve($url,$canonical)); $canonicalStatus = $resolvedCanonical === $url ? 'pass' : 'warn'; } catch (\Throwable $e) {} }
        $add('canonical','Meta Tags','Canonical URL',$canonicalStatus,$canonical === '' ? 'No canonical link found.' : ($resolvedCanonical ?: 'Invalid canonical: ' . $canonical),'Use a valid canonical for the preferred page. Pointing elsewhere may intentionally consolidate this URL.');
        $robots = [];
        foreach (['robots','googlebot'] as $agent) {
            foreach ($html->nodes('//head/meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="' . $agent . '"]') as $meta) { $robots[] = $meta->getAttribute('content'); }
        }
        $allRobots = strtolower(implode(', ',$robots) . ', ' . implode(', ',$page['headers']['x-robots-tag'] ?? []));
        $noindex = preg_match('/\b(noindex|none)\b/',$allRobots) === 1;
        $add('indexable','Crawlability','Page can be indexed',$noindex ? 'fail' : 'pass',$noindex ? 'CRITICAL: noindex (or none) instructs search engines not to index this page. Found in robots/Googlebot metadata or X-Robots-Tag.' : 'No noindex found in robots/Googlebot metadata or HTTP headers. Indexing is not guaranteed.','For a page intended to appear in search, remove noindex/none from the page or HTTP headers. Also allow crawling so search engines can read the corrected directive. Keep restrictions only if exclusion is intentional.');
        $nofollow = preg_match('/\b(nofollow|none)\b/',$allRobots) === 1;
        $add('followable','Crawlability','Page allows link following',$nofollow ? 'fail' : 'pass',$nofollow ? 'MAJOR: page-level nofollow (or none) tells search engines not to follow links on this page. This affects link discovery; it does not itself prevent this page from being indexed.' : 'No page-level nofollow directive found in robots/Googlebot metadata or HTTP headers. Individual link attributes are not evaluated here.','Remove unintended page-level nofollow/none so the page can support link discovery. Keep individual link restrictions where appropriate; review deliberately restricted pages separately.');
        $add('viewport','Technical','Mobile viewport',$html->meta('viewport') ? 'pass' : 'fail',$html->meta('viewport') ?: 'No viewport metadata found.','Add width=device-width, initial-scale=1 and test the mobile layout.');
        $add('https','Technical','Served over HTTPS',parse_url($url,PHP_URL_SCHEME) === 'https' ? 'pass' : 'fail',$url,'Serve the page over HTTPS and redirect HTTP.');
        $len = mb_strlen($url); $query = (bool)parse_url($url,PHP_URL_QUERY);
        $add('url','Technical','Clean, concise URL',$len <= 75 && !$query ? 'pass' : ($len <= 90 || $query ? 'warn' : 'fail'),$len . ' characters' . ($query ? '; contains a query string.' : '; no query string.'),'Prefer readable, stable URLs. Query strings are not inherently an SEO problem.');
        $headings = []; $issues = [];
        foreach ($html->nodes('//h1|//h2|//h3|//h4|//h5|//h6') as $node) {
            $level = (int)substr($node->nodeName,1); $index = count($headings); $issue = '';
            if ($index === 0 && $level !== 1) { $issue = 'Page starts with H' . $level . ' rather than H1.'; }
            elseif ($index > 0 && $level > $headings[$index-1]['level'] + 1) { $issue = 'H' . $level . ' appears after H' . $headings[$index-1]['level'] . ', skipping a heading level.'; }
            $headings[] = ['level'=>$level,'text'=>mb_substr(Html::clean($node->textContent),0,300),'issue'=>$issue];
            if ($issue !== '') { $issues[] = ['index'=>$index,'message'=>$issue]; }
        }
        $h1 = array_values(array_filter($headings,function ($h) { return $h['level'] === 1; }));
        $add('h1','Headings','One clear H1',count($h1) === 1 ? 'pass' : 'fail',count($h1) . ' H1 heading(s).','Use a clear main heading. A single H1 is this tool’s convention; multiple H1s are not an automatic Google penalty.');
        if ($h1) { $empty = count(array_filter($h1,function ($h) { return $h['text'] === ''; })); $add('h1-content','Headings','H1 has meaningful text',$empty ? 'fail' : 'pass',$empty ? $empty . ' empty H1(s).' : $h1[0]['text'],'Give the main heading descriptive text.'); }
        $add('hierarchy','Headings','Heading hierarchy',$headings && !$issues ? 'pass' : 'fail',$headings ? count($issues) . ' level-skipping issue(s) across ' . count($headings) . ' headings.' : 'No heading tags found.','Start with H1 and increase depth one level at a time. Returning to a lower number is fine.');
        $sub = count($headings)-count($h1);
        $add('subheadings','Headings','Helpful subheadings',$sub >= 2 ? 'pass' : ($sub === 1 ? 'warn' : 'fail'),$sub . ' subheading(s).','Use H2/H3 headings to organise substantial content. Short pages may need fewer.');
        $images = $html->nodes('//img'); $missing = 0; $decorative = 0;
        foreach ($images as $img) { if (!$img->hasAttribute('alt')) { $missing++; } elseif (trim($img->getAttribute('alt')) === '') { $decorative++; } }
        $add('alt','Content','Image alternative text',!$images ? 'warn' : ($missing === count($images) ? 'fail' : (($missing || $decorative) ? 'warn' : 'pass')),count($images) . ' images; ' . $missing . ' missing alt attributes; ' . $decorative . ' empty alt values.','Describe informative images. Empty alt is correct for decorative images; review their purpose manually.');
        $base = $url; $baseTag = $html->attr('//head/base[@href]','href');
        if ($baseTag !== '') { try { $base = Http::normalize(Http::resolve($url,$baseTag)); } catch (\Throwable $e) {} }
        $internal = 0; $external = 0;
        foreach ($html->nodes('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || preg_match('/^(#|mailto:|tel:|javascript:|data:)/i',$href)) { continue; }
            try { $target = Http::normalize(Http::resolve($base,$href)); if (parse_url($target,PHP_URL_HOST) === parse_url($url,PHP_URL_HOST)) { $internal++; } else { $external++; } } catch (\Throwable $e) {}
        }
        $add('internal','Links','Internal links',$internal ? 'pass' : 'fail',$internal . ' same-host link(s). Destinations are not crawled.','Link to relevant pages on your site with descriptive anchor text.');
        $add('external','Links','Outbound references',$external ? 'pass' : 'warn',$external . ' external link(s). Source quality is not verified.','Cite relevant primary sources where useful. Not every page needs external links.');
        $og = 0; foreach (['og:title','og:description','og:image'] as $key) { if ($html->meta($key,'property')) { $og++; } }
        $add('og','Social & Rich','Open Graph metadata',$og === 3 ? 'pass' : ($og ? 'warn' : 'fail'),$og . '/3 core Open Graph fields.','Add og:title, og:description and an absolute og:image URL.');
        $add('twitter','Social & Rich','Social card type',$html->meta('twitter:card') ? 'pass' : 'warn',$html->meta('twitter:card') ?: 'No Twitter/X card metadata found.','Add twitter:card, typically summary_large_image.');
        $schemas = []; $invalid = 0; $types = []; $author = false; $dated = false;
        $walk = function ($value) use (&$walk,&$types,&$author,&$dated) {
            if (!is_array($value)) { return; }
            if (isset($value['@type'])) { foreach ((array)$value['@type'] as $type) { if (is_string($type)) { $types[] = $type; } } }
            if (!empty($value['author'])) { $author = true; } if (!empty($value['datePublished']) || !empty($value['dateModified'])) { $dated = true; }
            foreach ($value as $child) { if (is_array($child)) { $walk($child); } }
        };
        foreach ($html->nodes('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]') as $node) {
            $value = json_decode($node->textContent,true,128);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($value)) { $invalid++; } else { $schemas[] = $value; $walk($value); }
        }
        $types = array_values(array_unique($types));
        $add('schema','Social & Rich','Valid JSON-LD markup',$invalid ? 'fail' : ($schemas && $types ? 'pass' : 'warn'),count($schemas) . ' valid JSON block(s), ' . $invalid . ' invalid. Types: ' . ($types ? implode(', ',$types) : 'none') . '. Full schema eligibility is not validated.','Fix JSON syntax, choose appropriate Schema.org types and validate rich-result eligibility separately.');
        $favicon = $html->attr('//head/link[contains(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"icon")]','href');
        $add('favicon','Technical','Favicon declaration',$favicon ? 'pass' : 'warn',$favicon ?: 'No favicon link found.','Declare a favicon. Its URL is not fetched by this audit.');
        $wordCount = $html->wordCount();
        if ($wordCount < 10 && !$headings) {
            $add('content-accessibility','Crawlability','Very little structured content is available to crawlers','fail',
                $wordCount . ' words detected in paragraph tags, with no H1-H6 headings in the initial HTML. This indicates a potential content-accessibility problem; text in other elements is not included in this count.',
                'If this page looks complete in your browser, its content may rely on JavaScript, embedded content, or non-standard markup that this HTML scan cannot fully interpret. This can make it harder for search engines and AI discovery systems to access and understand the page, potentially limiting its visibility in traditional and AI-powered search (SEO/GEO). It does not prove the page cannot be indexed. Check the rendered page and ensure meaningful text and descriptive headings are available in crawlable HTML, ideally in the initial server response.');
        }

        $add('word-count','Content','Sufficient textual content',$wordCount >= 300 ? 'pass' : ($wordCount >= 100 ? 'warn' : 'fail'),$wordCount . ' words (from <p> and heading tags).','Review whether the page answers its intended question. The 100/300 thresholds are tool heuristics, not search-engine minimums. Paragraphs/headings inside navigation and footers also count.');
        $lang = $html->attr('//html','lang');
        $add('lang','Technical','Document language',$lang ? 'pass' : 'warn',$lang ?: 'No html lang attribute found.','Set an appropriate language code on the html element.');
        $add('geo-structure','GEO signals','Content is easy to extract',$wordCount >= 100 && $sub >= 2 ? 'pass' : 'warn',$wordCount . ' paragraph/heading words and ' . $sub . ' subheadings in initial HTML.','Provide clear sections and useful text in the initial HTML. This is a structural indicator, not an AI citation prediction.');
        $author = $author || $html->meta('author') !== '' || count($html->nodes('//a[contains(concat(" ",@rel," ")," author ")]')) > 0;
        $add('geo-author','GEO signals','Authorship signals',$author ? 'pass' : 'warn',$author ? 'An author signal was found in metadata, JSON-LD or an author link.' : 'No machine-readable author signal found.','For authored articles, identify the responsible person or organisation and relevant expertise. Not all pages need a byline.');
        $dated = $dated || $html->meta('article:published_time','property') !== '' || count($html->nodes('//time[@datetime]')) > 0;
        $add('geo-date','GEO signals','Publication or update signals',$dated ? 'pass' : 'warn',$dated ? 'A machine-readable date signal was found; accuracy needs human review.' : 'No publication/update date signal found.','Where freshness matters, show accurate publication and update dates. Avoid changing dates without meaningful updates.');
        $restricted = preg_match('/\bnosnippet\b|max-snippet\s*:\s*0\b/',$allRobots);
        $add('geo-snippet','GEO signals','Snippet availability',$restricted ? 'warn' : 'pass',$restricted ? 'Snippet restrictions were found.' : 'No standard full snippet restriction found. Provider-specific crawler policies are not evaluated.','Review restrictions against your publishing policy. AI training controls and search visibility are distinct.');
        $blocks = [];
        foreach ($html->nodes('//body//h1|//body//h2|//body//h3|//body//h4|//body//h5|//body//h6|//body//p|//body//nav|//body//img') as $node) {
            if (count($blocks) >= 100) { break; } $tag = $node->nodeName; $text = Html::clean($node->textContent);
            if ($tag === 'img') { $text = $node->getAttribute('alt') ?: $node->getAttribute('src'); }
            if ($tag === 'p' && mb_strlen($text) < 4) { continue; }
            $blocks[] = ['type'=>$tag,'text'=>mb_substr($text,0,$tag === 'nav' ? 90 : 320)];
        }
        if (!$blocks) { $blocks[] = ['type'=>'p','text'=>mb_substr($html->text('//body'),0,600)]; }
        return self::finalize(['url'=>$url,'finalUrl'=>$url,'httpStatus'=>$page['status'],'redirects'=>$page['chain'] ?? [],'checks'=>$checks,'headings'=>array_slice($headings,0,500),'headingIssues'=>array_slice($issues,0,500),'headingCount'=>count($headings),'blocks'=>$blocks,'metadata'=>['title'=>$title,'description'=>$description,'canonical'=>$canonical,'og:image'=>$html->meta('og:image','property'),'robots'=>implode(', ',$robots)],'wordCount'=>$wordCount,'robotsTxt'=>null,'sitemapXml'=>null,'incomplete'=>false,'accessibility'=>(new Accessibility())->analyze($html)]);
    }
    public function slash(Http $http, array $page): array {
        $url = $page['url']; $p = parse_url($url); $path = $p['path'] ?? '/';
        $check = function ($status,$detail,$rec = '') { return self::check('trailing-slash','Technical','Trailing slash consistency',$status,$detail,$rec); };
        if ($path === '' || $path === '/') { return $check('pass','Root URL has no separate trailing-slash variant.'); }
        $alternate = $p['scheme'] . '://' . $p['host'] . (substr($path,-1) === '/' ? rtrim($path,'/') : $path . '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $v = $http->get($alternate,false); $status = $v['status'];
        if ($status >= 300 && $status < 400) {
            $target = isset($v['headers']['location'][0]) ? Http::normalize(Http::resolve($alternate,$v['headers']['location'][0])) : '';
            return $check($target === $url && in_array($status,[301,308],true) ? 'pass' : 'warn','Alternate form returns HTTP ' . $status . ' to ' . ($target ?: 'an unspecified target') . '.','Use a 301/308 redirect to the preferred URL, preserving intended query parameters.');
        }
        if (in_array($status,[404,410],true)) { return $check('pass','Alternate form returns HTTP ' . $status . '; no duplicate page.'); }
        if ($status < 200 || $status >= 300 || stripos($v['type'],'html') === false) { return $check('warn','Alternate form returned HTTP ' . $status . '; equivalence could not be verified.','Check manually. A 403/429 does not prove the page is absent.'); }
        $a = new Html($page['body']); $b = new Html($v['body']); $sa = $a->signature(); $sb = $b->signature();
        $ratio = function ($x,$y) { return max($x,$y) ? min($x,$y)/max($x,$y) : 1; };
        $sameCanonical = $a->canonical() && $b->canonical() && Http::resolve($url,$a->canonical()) === Http::resolve($alternate,$b->canonical());
        $same = Html::clean($page['body']) === Html::clean($v['body']) || ($sa['title'] === $sb['title'] && $sa['h1'] === $sb['h1'] && ($ratio($sa['words'],$sb['words']) >= .95 || $ratio(strlen($page['body']),strlen($v['body'])) >= .97)) || $sameCanonical;
        if ($same && $sa['words'] < 100 && $sb['words'] < 100) { return $check('warn','Both forms respond with similar short HTML; this may be a JavaScript shell.','Verify rendered content and redirect the alternate if it is the same page.'); }
        return $check($same ? ($sameCanonical ? 'warn' : 'fail') : 'pass',$same ? 'Both forms return similar HTML at HTTP 2xx.' . ($sameCanonical ? ' A shared canonical signals consolidation.' : ' No shared canonical found.') : 'The alternate form appears to contain different content.',$same ? 'A permanent redirect is the clearest consolidation. Similarity is a heuristic; review both pages before changing routing.' : '');
    }
    public static function finalize(array $r): array {
        $r['passed'] = $r['failed'] = $r['warned'] = 0;
        $seoPoints = 0; $seoWeight = 0; $geoPoints = 0; $geoCount = 0;
        $cap = 100; $capReasons = []; $urgent = false;
        foreach ($r['checks'] as $c) {
            $r[['pass'=>'passed','fail'=>'failed','warn'=>'warned'][$c['status']]]++;
            $points = ['pass'=>1,'warn'=>.5,'fail'=>0][$c['status']];
            if ($c['category'] === 'GEO signals') { $geoPoints += $points; $geoCount++; continue; }
            $weight = $c['category'] === 'Crawlability' ? 5 : 1;
            $seoPoints += $points * $weight; $seoWeight += $weight;
            if ($c['category'] === 'Crawlability' && $c['status'] === 'fail') {
                $urgent = true;
                $caps = ['indexable'=>20,'followable'=>40,'robots-txt'=>35];
                if (isset($caps[$c['id']])) {
                    $cap = min($cap,$caps[$c['id']]);
                    $capReasons[] = $c['title'] . ': maximum ' . $caps[$c['id']] . '/100.';
                }
            }
        }
        $raw = $seoWeight ? (int)round($seoPoints/$seoWeight*100) : 0;
        $r['score'] = !empty($r['incomplete']) ? null : min($raw,$cap);
        $r['scoreCap'] = $cap < 100 && empty($r['incomplete']) ? $cap : null;
        $r['scoreCapReasons'] = $capReasons;
        $r['crawlabilityUrgent'] = $urgent;
        $r['geoScore'] = $geoCount ? (int)round($geoPoints/$geoCount*100) : null;
        $r['engineVersion'] = self::VERSION;
        $r['scoringVersion'] = 'crawlability-weighted-v2';
        return $r;
    }
}
