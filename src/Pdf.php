<?php
declare(strict_types=1);
namespace Audit;
final class Pdf {
    private static function e($s): string {
        $s=mb_substr((string)$s,0,4000);
        // Break long tokens for predictable PDF wrapping. No audited HTML is rendered.
        $s=preg_replace_callback('/\S{60,}/u',function($m){return implode(' ',mb_str_split($m[0],50,'UTF-8'));},$s);
        return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    public static function render(array $r): string {
        require_once \APP_ROOT.'/vendor/dompdf/autoload.inc.php';
        $cache=\app_config('storage_path').'/pdf-cache';
        if(!is_dir($cache)&&!mkdir($cache,0700,true))throw new \RuntimeException('PDF storage is unavailable.');
        $options=new \Dompdf\Options();
        $options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('isJavascriptEnabled',false);
        $options->set('chroot',\APP_ROOT.'/vendor/dompdf');$options->set('tempDir',$cache);$options->set('fontCache',$cache);$options->set('defaultFont','DejaVu Sans');
        $pdf=new \Dompdf\Dompdf($options);
        $e=[self::class,'e'];
        $html='<!doctype html><html><head><meta charset="utf-8"><style>
        @page{margin:42px 42px 48px}body{font-family:"DejaVu Sans",sans-serif;color:#28243a;font-size:9px;line-height:1.5}
        h1{font-size:25px;color:#6545c5;margin:0 0 9px}h2{font-size:15px;margin:22px 0 10px;padding-bottom:6px;border-bottom:1px solid #ded8ec;page-break-after:avoid}
        h3{font-size:11px;margin:14px 0 5px;page-break-after:avoid}p{margin:5px 0 9px}.muted{color:#686376}.score{font-size:18px;background:#f2edfd;padding:12px}
        .check{page-break-inside:avoid;border-left:3px solid #d7cee9;padding:2px 0 2px 10px;margin:12px 0}.status{font-size:8px;text-transform:uppercase;font-weight:bold}
        .fail{color:#991b1b}.warn{color:#926117}.pass{color:#25745a}ul,ol{padding-left:17px;margin:6px 0 10px}.note{background:#fff5df;padding:10px}
        .label{font-weight:bold}.inventory{border-top:1px solid #e7e2ef;padding:8px 0;page-break-inside:avoid}
        </style></head><body><h1>X-Ray SEO Audit</h1><p>'.$e($r['finalUrl']).'</p><p class="muted">Audit '.$e($r['id']).' | Saved '.$e($r['createdAt']).' | Engine '.$e($r['engineVersion']).'</p>';
        $html.='<div class="score">SEO: '.($r['score']===null?'Not scored':$e($r['score']).'/100').' &nbsp; GEO indicators: '.($r['geoScore']===null?'Not scored':$e($r['geoScore']).'/100').'</div>';
        $html.='<p>'.$e($r['passed']).' passed | '.$e($r['failed']).' to fix | '.$e($r['warned']).' to review</p>';
        $html.='<p class="muted">This is a saved raw-HTML diagnostic, not a ranking or AI-citation prediction. Word count includes paragraph and heading text only. '.$e($r['scoringVersion']==='crawlability-weighted-v2'?'Crawlability checks have 5x weight; warnings earn half credit. Critical caps: noindex 20, robots.txt failure 35, page-level nofollow 40. Lowest cap wins.':'This report uses the original equal-weight scoring model.').'</p>';
        $deep=$r['advanced']['result'] ?? null;
        if($deep)$html.='<p class="note">In-depth review: '.$e($deep['checkedAt']).'. '.$e($deep['note']).'</p>';
        $categories=array_values(array_unique(array_column($r['checks'],'category')));
        usort($categories,function($a,$b){return ($a==='Crawlability'?0:1)<=>($b==='Crawlability'?0:1);});
        foreach($categories as $category) {
            $html.='<h2>'.$e($category).'</h2>';
            if(isset($deep['guidance']['sections'][$category]))$html.='<p>'.$e($deep['guidance']['sections'][$category]['why']).'</p>';
            if($category==='Crawlability') {
                foreach(['robotsTxt','sitemapXml'] as $key) {
                    if(empty($r[$key]))continue;$f=$r[$key];
                    $html.='<h3>'.$e($key==='robotsTxt'?'robots.txt':'XML sitemap').'</h3><p>'.$e($f['url']).' | HTTP '.$e($f['status']).'</p><ul>';
                    foreach($f['notes'] as $note)$html.='<li>'.$e($note).'</li>';
                    $html.='</ul>';
                }
            }
            foreach($r['checks'] as $check) {
                if($check['category']!==$category)continue;
                $html.='<div class="check"><h3>'.$e($check['title']).' <span class="status '.$e($check['status']).'">'.$e(['pass'=>'Pass','fail'=>'Fix','warn'=>'Review'][$check['status']]).'</span></h3><p>'.$e($check['detail']).'</p>';
                if(!empty($check['recommendation']))$html.='<p><b>Recommendation:</b> '.$e($check['recommendation']).'</p>';
                if(isset($deep['guidance']['checks'][$check['id']])) {
                    $g=$deep['guidance']['checks'][$check['id']];$html.='<p><b>In-depth action steps:</b></p><ol>';
                    foreach($g['steps'] as $step)$html.='<li>'.$e($step).'</li>';
                    $html.='</ol><p><b>Verify:</b> '.$e($g['verify']).'</p>';
                }
                $html.='</div>';
            }
        }
        if(!empty($r['headings'])) {
            $html.='<h2>Heading structure</h2>';
            if(count($r['headings'])>200)$html.='<p>PDF shows the first 200 headings. Open the saved report for the full available outline.</p>';
            foreach(array_slice($r['headings'],0,200) as $h)$html.='<p>H'.$e($h['level']).' - '.$e($h['text'] ?: '(empty)').(!empty($h['issue'])?' | '.$e($h['issue']):'').'</p>';
        }
        if($deep) {
            $map=$deep['sitemap'];$html.='<h2>In-depth sitemap review</h2><p>'.$e($map['entriesReviewed']).' entries reviewed across '.count($map['files']).' files.</p><p>'.$e($map['limits']).'</p>';
            foreach($map['notes'] as $note)$html.='<p class="warn">'.$e($note).'</p>';
            if(!$map['findings'])$html.='<p>No potential issues were flagged in the reviewed entries. This does not certify unreviewed URLs.</p>';
            if(count($map['findings'])>50)$html.='<p>PDF shows the first 50 flagged entries. Open the saved report or export JSON for the full reviewed list.</p>';
            foreach(array_slice($map['findings'],0,50) as $f)$html.='<div class="inventory"><p>'.$e($f['url']).'</p><p>'.$e(implode('; ',$f['reasons'])).'</p></div>';
            $images=$deep['images'];$html.='<h2>Image alternative-text inventory</h2><p>'.$e($images['total']).' images; '.$e($images['missing']).' missing alt; '.$e($images['empty']).' empty alt; '.$e($images['present']).' with alt text.</p><p>'.$e($images['note']).'</p>';
            if($images['truncated'])$html.='<p>Inventory limited to the first 250 images.</p>';
            if(count($images['items'])>100)$html.='<p>PDF shows the first 100 listed images, prioritising missing alt text. Open the saved report or export JSON for the full inventory.</p>';
            foreach(array_slice($images['items'],0,100) as $img)$html.='<div class="inventory"><p><b>Image '.$e($img['position']).' - '.$e($img['status']).' alt</b></p><p>'.$e($img['sourceLabel']).'</p><p>Alt: '.$e(mb_substr($img['alt'] ?: '(none)',0,400)).'</p></div>';
        }
        $a=$r['accessibility']??Accessibility::unavailable('This saved audit predates accessibility checks. Run a new audit.');
        $html.='<h2>Accessibility &amp; ARIA</h2><p><b>'.$e(['pass'=>'Pass','fail'=>'Fail','not_checked'=>'Not checked'][$a['status']]??'Not checked').'</b> - '.$e($a['summary']).'</p><p class="muted">'.$e($a['scope']).' These checks do not affect the SEO score.</p>';
        if (!($a['detailsLocked']??false)) {
            $html.='<p>'.$e($a['counts']['fail']??0).' to fix; '.$e($a['counts']['warn']??0).' to review. No in-depth credits needed.</p>';
            if($a['truncated']??false)$html.='<p class="note">Limited scan: at most 5,000 elements checked and 150 findings stored. Review the full page manually.</p>';
            foreach($a['findings']??[] as $f) {
                $html.='<div class="check"><h3>'.$e($f['title']).' <span class="status">'.$e($f['status']==='fail'?'Fix':'Review').'</span></h3><p>'.$e($f['detail']).'</p><p><b>Element:</b> '.$e($f['element']).'</p><p>'.$e($f['snippet']).'</p><p><b>Fix:</b> '.$e($f['fix']).'</p><p><b>Example:</b> '.$e($f['example']).'</p><p><b>Verify:</b> '.$e($f['verify']).'</p></div>';
            }
            if(!empty($a['manualChecks'])) {
                $html.='<h3>What to check in a browser</h3><ul>';
                foreach($a['manualChecks'] as $item)$html.='<li>'.$e($item).'</li>';
                $html.='</ul>';
            }
        }
        $html.='</body></html>';
        $pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();
        $font=$pdf->getFontMetrics()->getFont('DejaVu Sans','normal');
        $pdf->getCanvas()->page_text(42,812,'X-Ray SEO | Page {PAGE_NUM} of {PAGE_COUNT}',$font,8,[.45,.42,.5]);
        return $pdf->output();
    }
}
