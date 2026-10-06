<?php
declare(strict_types=1);
namespace Audit;
final class Advanced {
    const VERSION='1.0.0';
    public static function images(Html $html,string $url): array {
        $base=$url;$baseTag=$html->attr('//head/base[@href]','href');
        if($baseTag!=='') { try{$base=Http::normalize(Http::resolve($url,$baseTag));}catch(\Throwable $e){} }
        $nodes=$html->nodes('//img');$rows=[];$missing=0;$empty=0;$present=0;
        foreach($nodes as $i=>$node) {
            $status=!$node->hasAttribute('alt')?'missing':(trim($node->getAttribute('alt'))===''?'empty':'present');
            if($status==='missing')$missing++;elseif($status==='empty')$empty++;else $present++;
            if(count($rows)>=250)continue;
            $raw=$node->getAttribute('src') ?: $node->getAttribute('data-src');
            $source='';if($raw!==''){try{$source=Http::normalize(Http::resolve($base,$raw));}catch(\Throwable $e){}}
            $rows[]=['position'=>$i+1,'source'=>$source,'sourceLabel'=>mb_substr($source ?: ($raw ?: 'No src/data-src; inspect srcset or the rendered page'),0,2048),'alt'=>mb_substr($node->getAttribute('alt'),0,1000),'status'=>$status,'width'=>$node->getAttribute('width'),'height'=>$node->getAttribute('height'),'loading'=>$node->getAttribute('loading')];
        }
        // Show actionable rows before adequately labelled images.
        usort($rows,function($a,$b){$rank=['missing'=>0,'empty'=>1,'present'=>2];return $rank[$a['status']]<=>$rank[$b['status']] ?: $a['position']<=>$b['position'];});
        return ['total'=>count($nodes),'missing'=>$missing,'empty'=>$empty,'present'=>$present,'listed'=>count($rows),'truncated'=>count($nodes)>250,'items'=>$rows,'note'=>'Only img elements in the current initial HTML are inspected. Empty alt can be correct for decorative images. CSS backgrounds, JavaScript-inserted images and source-set alternatives are not enumerated. Image files are not fetched.'];
    }
    public static function xmlEntries(string $body): array {
        if(stripos($body,'<!DOCTYPE')!==false || stripos($body,'<!ENTITY')!==false)throw new \RuntimeException('DTD and entity declarations are not supported.');
        $old=libxml_use_internal_errors(true);$doc=new \DOMDocument();
        $ok=$doc->loadXML($body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($old);
        $root=$ok&&$doc->documentElement?$doc->documentElement->localName:'';
        if(!in_array($root,['urlset','sitemapindex'],true))throw new \RuntimeException('Expected well-formed urlset or sitemapindex XML.');
        $xp=new \DOMXPath($doc);$tag=$root==='urlset'?'url':'sitemap';
        $nodes=$xp->query('/*/*[local-name()="'.$tag.'"]');$entries=[];
        foreach($nodes as $n) {
            if(count($entries)>=1000)break;
            $loc=$xp->query('./*[local-name()="loc"]',$n);$date=$xp->query('./*[local-name()="lastmod"]',$n);
            $entries[]=['url'=>$loc->length?trim($loc->item(0)->textContent):'','lastmod'=>$date->length?trim($date->item(0)->textContent):''];
        }
        return ['type'=>$root,'entries'=>$entries,'truncated'=>$nodes->length>1000,'total'=>$nodes->length];
    }
    public static function urlReasons(string $url,string $pageUrl,string $lastmod=''): array {
        $reasons=[];$p=parse_url($url);$host=strtolower($p['host'] ?? '');$path=rawurldecode($p['path'] ?? '');
        if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array($p['scheme'] ?? '',['http','https'],true)) { return ['Invalid or non-HTTP absolute URL']; }
        if(preg_match('~(?:^|[./_-])(test|testing|staging|stage|dev|development|demo|sample|dummy|temp|temporary|backup|draft|sandbox)(?:[./_-]|$)~i',$host.$path) || preg_match('~/(sample-page|hello-world)(?:/|$)~i',$path))$reasons[]='Possible test, staging or placeholder URL - verify its purpose';
        if($host!==strtolower(parse_url($pageUrl,PHP_URL_HOST)))$reasons[]='Different hostname from the audited page';
        if(($p['scheme'] ?? '')==='http' && parse_url($pageUrl,PHP_URL_SCHEME)==='https')$reasons[]='HTTP URL on an HTTPS site';
        if(isset($p['fragment']))$reasons[]='Fragment identifier included';
        if(isset($p['query']))$reasons[]='Query parameters - check for duplicate, tracking or preview URLs';
        if(preg_match('~/(?:wp-admin|wp-login\.php|login|admin|cart|checkout)(?:/|$)~i',$path))$reasons[]='Potential utility/private page - check whether it belongs in the sitemap';
        if($lastmod!=='') {
            if(!preg_match('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/',$lastmod) || strtotime($lastmod)===false)$reasons[]='Invalid lastmod date';
            elseif(strtotime($lastmod)>time()+86400)$reasons[]='Lastmod date is in the future';
        }
        return $reasons;
    }
    public static function sitemap(Http $http,string $pageUrl,string $start): array {
        $queue=[$start];$seenMaps=[];$seenUrls=[];$findings=[];$files=[];$notes=[];$entries=0;$truncated=false;$contains=false;
        while($queue && count($seenMaps)<4 && $entries<1000) {
            $next=array_shift($queue);
            try {
                $next=Http::normalize($next);
                if(parse_url($next,PHP_URL_HOST)!==parse_url($pageUrl,PHP_URL_HOST)){ $notes[]='Skipped a cross-host sitemap reference.';continue; }
                if(isset($seenMaps[$next]))continue;$seenMaps[$next]=true;
                $r=$http->get($next);
                if($r['status']<200||$r['status']>=300)throw new \RuntimeException('HTTP '.$r['status']);
                if(Auxiliary::isHtml($r))throw new \RuntimeException('Returned HTML instead of sitemap XML.');
                $xml=self::xmlEntries($r['body']);$truncated=$truncated||$xml['truncated'];
                $files[]=['url'=>$r['url'],'status'=>$r['status'],'type'=>$xml['type'],'entries'=>$xml['total']];
                foreach($xml['entries'] as $entry) {
                    if($xml['type']==='sitemapindex') {
                        if(count($queue)<20)$queue[]=$entry['url'];else $truncated=true;
                        continue;
                    }
                    if($entries>=1000){$truncated=true;break;}$entries++;
                    $url=$entry['url'];$reasons=self::urlReasons($url,$pageUrl,$entry['lastmod']);
                    if(isset($seenUrls[$url]))$reasons[]='Duplicate sitemap entry';
                    $seenUrls[$url]=true;
                    try { if(Http::normalize($url)===Http::normalize($pageUrl))$contains=true; }catch(\Throwable $e){}
                    if($reasons && count($findings)<200)$findings[]=['url'=>mb_substr($url,0,2048),'lastmod'=>mb_substr($entry['lastmod'],0,80),'reasons'=>$reasons];
                    elseif($reasons)$truncated=true;
                }
            } catch(\Throwable $e) { $notes[]=$next.': '.$e->getMessage(); }
        }
        if($queue)$truncated=true;
        return ['files'=>$files,'entriesReviewed'=>$entries,'uniqueUrls'=>count($seenUrls),'findings'=>$findings,'containsAuditedUrl'=>$contains,'truncated'=>$truncated,'notes'=>$notes,'limits'=>'Reviews at most 4 same-host sitemap files, 1,000 URL entries and 200 flagged entries within the scan time budget. Listed pages are not fetched. Possible test URLs are heuristics, not confirmed mistakes. An absent URL is not evidence of an indexing problem, especially when the review is partial.'];
    }
    public function run(array $basic): array {
        $http=new Http();$page=$http->get($basic['finalUrl']);
        if($page['status']<200||$page['status']>=300||stripos($page['type'],'html')===false||$page['body']==='')throw new \RuntimeException('The page could not be fetched as successful HTML. No in-depth credit was used.');
        $html=new Html($page['body']);
        $origin=parse_url($page['url'],PHP_URL_SCHEME).'://'.parse_url($page['url'],PHP_URL_HOST);
        $map=$basic['sitemapXml']['url'] ?? $origin.'/sitemap.xml';
        return ['version'=>self::VERSION,'checkedAt'=>gmdate('c'),'pageUrl'=>$page['url'],'images'=>self::images($html,$page['url']),'sitemap'=>self::sitemap($http,$page['url'],$map),'guidance'=>Guidance::forReport($basic),'note'=>'Sitemap and image inventories are fetched at the time of this in-depth scan. Explanations refer to the saved basic findings; the page may have changed since that audit. The basic report and score are not overwritten.'];
    }
}
