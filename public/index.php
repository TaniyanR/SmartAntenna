<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(!is_file($root.'/.env')){header('Location: /install.php');exit;}
require $root.'/app/bootstrap.php';

try{sa_track_in();sa_maybe_refresh();}catch(Throwable $e){sa_log('front-init',$e->getMessage());}

$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/';
$method=$_SERVER['REQUEST_METHOD']??'GET';

function front_ad(string $pos):string{try{$st=sa_db()->prepare('SELECT html FROM ads WHERE position_key=? AND is_enabled=1');$st->execute([$pos]);$h=$st->fetchColumn();return $h?'<div class="ad">'.$h.'</div>':'';}catch(Throwable){return '';}}
function front_header(string $title='',string $desc=''):void{
 $canonical=sa_url(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');$name=(string)sa_setting('site_name','SmartAntenna');$d=$desc?:$name.'の新着記事・人気記事をまとめて紹介するアンテナサイトです。';
 ?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=sa_e(sa_page_title($title))?></title><meta name="description" content="<?=sa_e($d)?>"><link rel="canonical" href="<?=sa_e($canonical)?>"><meta property="og:title" content="<?=sa_e(sa_page_title($title))?>"><meta property="og:description" content="<?=sa_e($d)?>"><meta property="og:url" content="<?=sa_e($canonical)?>"><meta property="og:type" content="website"><meta property="og:site_name" content="<?=sa_e($name)?>"><meta name="twitter:card" content="summary"><link rel="stylesheet" href="/assets/css/app.css"><script>window.SA_CSRF='<?=sa_e(sa_csrf_token())?>';</script><script src="/assets/js/app.js" defer></script></head><body><header class="site-header"><div class="inner"><a class="brand" href="/"><?=sa_e($name)?></a><nav class="nav" aria-label="主要メニュー"><a href="/">新着</a><a href="/register">サイト登録</a><a href="/p/about">サイトについて</a><a href="/p/privacy-policy">プライバシー</a></nav></div></header><main class="container"><?=front_ad('header')?><?php }
function front_footer():void{?><footer class="footer"><div class="inner">&copy; <?=date('Y')?> <?=sa_e(sa_setting('site_name','SmartAntenna'))?> All Rights Reserved.</div></footer></main></body></html><?php }
function article_list(array $rows,string $track='list'):void{echo '<ul class="article-list">';foreach($rows as $r){$dt=$r['published_at']?:$r['created_at'];echo '<li><span class="time">'.sa_e(date('H:i',strtotime($dt))).'｜</span><a data-track="'.sa_e($track).'" data-article="'.(int)$r['id'].'" href="/article/'.(int)$r['id'].'">'.sa_e($r['title']).'</a></li>';}if(!$rows)echo '<li class="muted">現在表示できる記事はありません。</li>';echo '</ul>';}

if($path==='/robots.txt'){
 header('Content-Type: text/plain; charset=UTF-8');echo "User-agent: *\nDisallow: /admin/\nDisallow: /install.php\nSitemap: ".sa_url('/sitemap.xml')."\n";exit;
}
if($path==='/sitemap.xml'){
 header('Content-Type: application/xml; charset=UTF-8');echo '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
 echo '<url><loc>'.sa_e(sa_url('/')).'</loc></url>';
 foreach(sa_db()->query("SELECT id FROM articles WHERE is_deleted=0 ORDER BY id DESC LIMIT 50000")->fetchAll() as $r)echo '<url><loc>'.sa_e(sa_url('/article/'.$r['id'])).'</loc></url>';
 foreach(sa_db()->query("SELECT slug FROM categories WHERE is_active=1")->fetchAll() as $r)echo '<url><loc>'.sa_e(sa_url('/category/'.$r['slug'])).'</loc></url>';
 foreach(sa_db()->query("SELECT slug FROM pages WHERE status='published'")->fetchAll() as $r)echo '<url><loc>'.sa_e(sa_url('/p/'.$r['slug'])).'</loc></url>';
 echo '</urlset>';exit;
}
if($path==='/rss.xml'){
 $type=(string)($_GET['type']??'general');$cat=(string)($_GET['category']??'');$where='1=1';$params=[];
 if($type==='adult')$where='a.is_adult=1';elseif($type==='general')$where='a.is_adult=0';
 if($cat!==''){$where.=' AND c.slug=?';$params[]=$cat;}
 $rows=sa_articles($where,$params,50);header('Content-Type: application/rss+xml; charset=UTF-8');echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";?><rss version="2.0"><channel><title><?=sa_e(sa_setting('site_name','SmartAntenna'))?></title><link><?=sa_e(sa_url('/'))?></link><description>SmartAntenna RSS</description><?php foreach($rows as $r):?><item><title><?=sa_e($r['title'])?></title><link><?=sa_e(sa_url('/article/'.$r['id']))?></link><guid><?=sa_e(sa_url('/article/'.$r['id']))?></guid><pubDate><?=date(DATE_RSS,strtotime($r['published_at']?:$r['created_at']))?></pubDate></item><?php endforeach?></channel></rss><?php exit;
}
if($path==='/event' && $method==='POST'){
 $type=substr((string)($_POST['event_type']??''),0,50);if($type==='internal_click'){$st=sa_db()->prepare('INSERT INTO analytics_events(event_type,article_id,page_type,target,ip_hash,ua_hash) VALUES(?,?,?,?,?,?)');$st->execute([$type,($_POST['article_id']??'')!==''?(int)$_POST['article_id']:null,'front',substr((string)($_POST['target']??''),0,255),sa_ip_hash(),sa_ua_hash()]);}http_response_code(204);exit;
}
if(preg_match('#^/out/(\d+)$#',$path,$m)){
 $a=sa_article((int)$m[1]);if(!$a||$a['is_deleted']||$a['status']??false){http_response_code(404);exit('Not Found');}sa_track_out($a);sa_redirect($a['original_url']);
}
if(preg_match('#^/article/(\d+)$#',$path,$m)){
 $a=sa_article((int)$m[1]);if(!$a){http_response_code(404);front_header('404');echo '<section class="panel"><h1>記事が見つかりません</h1></section>';front_footer();exit;}
 if($a['is_deleted']){http_response_code(410);front_header('削除済み記事');echo '<section class="panel"><h1>この記事は削除されています</h1><p>元記事へのリンクは表示していません。</p></section>';front_footer();exit;}
 sa_track_pv((int)$a['id']);$rec=sa_articles('a.id<>? AND (a.category_id=? OR a.site_id=?)',[$a['id'],$a['category_id'],$a['site_id']],12);front_header($a['title']);
 echo '<div class="breadcrumb"><a href="/">トップ</a> &gt; '.($a['category_slug']?'<a href="/category/'.sa_e($a['category_slug']).'">'.sa_e($a['category_name']).'</a> &gt; ':'').sa_e($a['title']).'</div><div class="grid"><div><section class="panel"><h1>'.sa_e($a['title']).'</h1><p class="muted">'.sa_e($a['site_name']).'</p><h2>おすすめ記事</h2>';
 $before=array_slice($rec,0,4);$after=array_slice($rec,4);article_list($before,'relay_recommended');
 echo '<p><a class="external-main" data-track="relay_out" data-article="'.(int)$a['id'].'" href="/out/'.(int)$a['id'].'" rel="nofollow">「'.sa_e($a['title']).'」の元記事を見る</a></p>';article_list($after,'relay_recommended');
 echo '<p><a href="/deletion-request?article_id='.(int)$a['id'].'">この記事の削除依頼</a></p></section></div><aside>'.front_ad('sidebar').'<section class="panel"><h2>人気記事 24時間</h2>';article_list(sa_rank_articles('out24',15),'popular24');echo '</section></aside></div>';front_footer();exit;
}
if(preg_match('#^/category/([a-z0-9_-]+)$#i',$path,$m)){
 $st=sa_db()->prepare('SELECT * FROM categories WHERE slug=? AND is_active=1');$st->execute([$m[1]]);$c=$st->fetch();if(!$c){http_response_code(404);exit('Not Found');}
 front_header($c['name']);echo '<section class="panel"><h1>'.sa_e($c['name']).'</h1>';article_list(sa_articles('a.category_id=?',[$c['id']],100),'category');echo '</section>';front_footer();exit;
}
if(preg_match('#^/date/(\d{4}-\d{2}-\d{2})$#',$path,$m)){
 $d=$m[1];front_header($d);echo '<section class="panel"><h1>'.sa_e($d).'</h1>';article_list(sa_articles('DATE(COALESCE(a.published_at,a.created_at))=?',[$d],200),'date');echo '</section>';front_footer();exit;
}
if(preg_match('#^/p/([a-z0-9_-]+)$#i',$path,$m)){
 $st=sa_db()->prepare("SELECT * FROM pages WHERE slug=? AND status='published'");$st->execute([$m[1]]);$p=$st->fetch();if(!$p){http_response_code(404);exit('Not Found');}front_header($p['title'],$p['meta_description']??'');echo '<section class="panel"><h1>'.sa_e($p['title']).'</h1><div>'.nl2br(sa_e($p['body'])).'</div></section>';front_footer();exit;
}
if($path==='/register'){
 $msg='';if($method==='POST'){sa_verify_csrf();if(!sa_honeypot_ok())exit('');$site=trim((string)$_POST['site_url']);$rss=trim((string)$_POST['rss_url']);$email=trim((string)$_POST['email']);if(!filter_var($site,FILTER_VALIDATE_URL)||!filter_var($rss,FILTER_VALIDATE_URL)||!filter_var($email,FILTER_VALIDATE_EMAIL))$msg='入力内容を確認してください。';else{$st=sa_db()->prepare('INSERT INTO registration_requests(site_name,site_url,rss_url,category_id,contact_name,email,note) VALUES(?,?,?,?,?,?,?)');$st->execute([trim((string)$_POST['site_name']),$site,$rss,(int)($_POST['category_id']??0)?:null,trim((string)$_POST['contact_name']),$email,trim((string)$_POST['note'])]);$msg='登録申請を受け付けました。管理人が確認します。';}}
 front_header('サイト登録');echo '<section class="panel"><h1>サイト登録申請</h1>'.($msg?'<div class="notice">'.sa_e($msg).'</div>':'').'<form method="post"><input type="hidden" name="_csrf" value="'.sa_e(sa_csrf_token()).'"><div class="hp"><label>Website<input name="website"></label></div><div class="form-row"><label>サイト名</label><input name="site_name" required></div><div class="form-row"><label>サイトURL</label><input type="url" name="site_url" required></div><div class="form-row"><label>RSS URL</label><input type="url" name="rss_url" required></div><div class="form-row"><label>カテゴリ</label><select name="category_id"><option value="">指定なし</option>';foreach(sa_db()->query('SELECT * FROM categories WHERE is_active=1 ORDER BY name')->fetchAll() as $c)echo '<option value="'.$c['id'].'">'.sa_e($c['name']).'</option>';echo '</select></div><div class="form-row"><label>お名前・ハンドルネーム</label><input name="contact_name"></div><div class="form-row"><label>メール</label><input type="email" name="email" required></div><div class="form-row"><label>備考</label><textarea name="note" rows="6"></textarea></div><button class="btn">申請する</button></form></section>';front_footer();exit;
}
if($path==='/deletion-request'){
 $articleId=(int)($_GET['article_id']??$_POST['article_id']??0);$msg='';if($method==='POST'){sa_verify_csrf();if(!sa_honeypot_ok())exit('');$a=sa_article($articleId);if(!$a)$msg='対象記事が見つかりません。';else{$st=sa_db()->prepare('INSERT INTO deletion_requests(article_id,site_id,reason,contact,body,requester_ip,user_agent) VALUES(?,?,?,?,?,?,?)');$st->execute([$articleId,$a['site_id'],trim((string)$_POST['reason']),trim((string)$_POST['contact']),trim((string)$_POST['body']),sa_ip(),sa_ua()]);$msg='削除依頼を受け付けました。自動削除は行わず、管理人が確認します。';}}
 front_header('削除依頼');echo '<section class="panel"><h1>削除依頼</h1>'.($msg?'<div class="notice">'.sa_e($msg).'</div>':'').'<form method="post"><input type="hidden" name="_csrf" value="'.sa_e(sa_csrf_token()).'"><input type="hidden" name="article_id" value="'.$articleId.'"><div class="hp"><label>Website<input name="website"></label></div><div class="form-row"><label>理由</label><input name="reason" required></div><div class="form-row"><label>連絡先</label><input name="contact"></div><div class="form-row"><label>本文</label><textarea name="body" rows="8"></textarea></div><button class="btn">送信</button></form></section>';front_footer();exit;
}
if($path!=='/'){http_response_code(404);front_header('404');echo '<section class="panel"><h1>ページが見つかりません</h1></section>';front_footer();exit;}

$latest=sa_articles('1=1',[],60);$popular24=sa_rank_articles('out24',25);$popular7=sa_rank_articles('out7',25);if(!$popular24)$popular24=array_slice($latest,0,25);if(!$popular7)$popular7=array_slice($latest,10,25);
front_header();echo '<div class="grid"><div><section class="panel"><h1>新着記事</h1>';article_list($latest,'latest');echo '</section>'.front_ad('between_new_recommended').'<section class="panel"><h2>おすすめ記事</h2>';article_list(array_slice(array_merge(sa_rank_articles('out7',30),$latest),0,30),'recommended');echo '</section>'.front_ad('between_recommended_popular').'<section class="panel"><h2>人気記事 24時間</h2>';article_list($popular24,'popular24');echo '</section><section class="panel"><h2>人気記事 7日間</h2>';article_list($popular7,'popular7');echo '</section></div><aside>'.front_ad('sidebar').'<section class="panel"><h2>カテゴリ</h2><ul>';foreach(sa_db()->query('SELECT * FROM categories WHERE is_active=1 ORDER BY sort_order,name')->fetchAll() as $c)echo '<li><a href="/category/'.sa_e($c['slug']).'">'.sa_e($c['name']).'</a></li>';echo '</ul></section><section class="panel"><h2>サイトランキング 24時間</h2>';
$st=sa_db()->prepare("SELECT payload FROM rank_cache WHERE cache_key='in24_site'");$st->execute();$rank=json_decode((string)$st->fetchColumn(),true)?:[];$ids=array_column(array_slice($rank,0,20),'id');if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$q=sa_db()->prepare("SELECT id,name FROM sites WHERE id IN($ph)");$q->execute($ids);$map=[];foreach($q->fetchAll() as $r)$map[$r['id']]=$r['name'];echo '<ol>';foreach($ids as $id)if(isset($map[$id]))echo '<li>'.sa_e($map[$id]).'</li>';echo '</ol>';}else echo '<p class="muted">集計待ちです。</p>';echo '</section></aside></div>';front_footer();
