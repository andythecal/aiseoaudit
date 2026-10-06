<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if(app_config('environment')!=='local')exit;
$html='<html lang="en"><head><title>Example page</title><meta name="robots" content="noindex,nofollow"></head><body><h1>Example page</h1><h2>Helpful details</h2><p>'.str_repeat('Useful page content with clear explanations. ',35).'</p><img src="/images/example.png"><img src="/images/decorative.png" alt=""></body></html>';
$r=(new Audit\Engine())->analyze(['url'=>'https://example.com/test-page/','status'=>200,'headers'=>[],'body'=>$html]);
$r['id']='pdf-layout-fixture';$r['createdAt']=gmdate('c');$r['state']='complete';
$r['advanced']=['state'=>'complete','result'=>['checkedAt'=>gmdate('c'),'note'=>'This fixture includes advanced guidance and inventories.','guidance'=>Audit\Guidance::forReport($r),'sitemap'=>['entriesReviewed'=>2,'files'=>[['url'=>'https://example.com/sitemap.xml']],'limits'=>'Fixture sample; potential test pages require manual review.','notes'=>[],'findings'=>[['url'=>'https://example.com/test-page/','reasons'=>['Possible test page - verify purpose']]]],'images'=>Audit\Advanced::images(new Audit\Html($html),'https://example.com/')]];
$pdf=Audit\Pdf::render($r);
if(substr($pdf,0,5)!=='%PDF-')throw new RuntimeException('Invalid PDF');
file_put_contents(app_config('storage_path').'/qa-member-report.pdf',$pdf);
echo 'PDF fixture generated: '.strlen($pdf)." bytes\n";

unset($r['advanced']);
file_put_contents(app_config('storage_path').'/qa-basic-member-report.pdf',Audit\Pdf::render($r));
echo "Basic member PDF fixture generated without in-depth data.\n";
