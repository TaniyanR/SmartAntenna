<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
sa_require_admin();
$pdo=sa_db();$section=preg_replace('/[^a-z0-9_-]/','',(string)($_GET['section']??'dashboard'));$msg='';

function adm_post(string $key): string{return trim((string)($_POST[$key]??''));}
if(sa_is_post()){
    sa_verify_csrf();$action=adm_post('action');
    try{
        switch($action){
            case 'refresh': sa_refresh_rss();sa_refresh_rankings();$msg='RSSとランキングを更新しました。';break;
            case 'save_site':
                $id=(int)($_POST['id']??0);$name=adm_post('name');$site=adm_post('site_url');$rss=adm_post('rss_url');$cat=(int)($_POST['category_id']??0)?:null;$status=adm_post('status')?:'active';
                if(!$name||!filter_var($site,FILTER_VALIDATE_URL)||!filter_var($rss,FILTER_VALIDATE_URL))throw new RuntimeException('サイト情報を確認してください。');
                if($id){$st=$pdo->prepare('UPDATE sites SET name=?,site_url=?,rss_url=?,category_id=?,status=? WHERE id=?');$st->execute([$name,$site,$rss,$cat,$status,$id]);}
                else{$st=$pdo->prepare('INSERT INTO sites(name,site_url,rss_url,category_id,status) VALUES(?,?,?,?,?)');$st->execute([$name,$site,$rss,$cat,$status]);}
                $msg='サイトを保存しました。';break;
            case 'delete_site':
                $id=(int)$_POST['id'];$pdo->prepare("UPDATE sites SET status='deleted' WHERE id=?")->execute([$id]);$pdo->prepare("UPDATE articles SET is_deleted=1,deleted_reason='サイト削除',deleted_at=NOW() WHERE site_id=?")->execute([$id]);$msg='サイトを削除扱いにしました。';break;
            case 'save_category':
                $id=(int)($_POST['id']??0);$name=adm_post('name');$slug=adm_post('slug');$adult=isset($_POST['is_adult'])?1:0;if(!$name||!preg_match('/^[a-z0-9_-]+$/i',$slug))throw new RuntimeException('カテゴリ名・slugを確認してください。');
                if($id)$pdo->prepare('UPDATE categories SET name=?,slug=?,is_adult=? WHERE id=?')->execute([$name,$slug,$adult,$id]);
                else $pdo->prepare('INSERT INTO categories(name,slug,is_adult) VALUES(?,?,?)')->execute([$name,$slug,$adult]);
                $msg='カテゴリを保存しました。';break;
            case 'article_status':
                $id=(int)$_POST['id'];$deleted=(int)($_POST['deleted']??0);$mode=(int)($_POST['adult_mode']??0);$isAdult=$mode===2?1:($mode===1?0:sa_classify_adult((string)($_POST['title']??'')));
                $pdo->prepare('UPDATE articles SET adult_mode=?,is_adult=?,is_deleted=?,deleted_reason=IF(?=1,\'管理者削除\',NULL),deleted_at=IF(?=1,NOW(),NULL) WHERE id=?')->execute([$mode,$isAdult,$deleted,$deleted,$deleted,$id]);
                if($deleted){$a=sa_article($id);if($a)$pdo->prepare('INSERT IGNORE INTO deleted_articles(url_hash,original_url,reason) VALUES(?,?,?)')->execute([$a['url_hash'],$a['original_url'],'管理者削除']);}
                $msg='記事を更新しました。';break;
            case 'save_settings':
                foreach(['site_name','refresh_interval','article_retention','ranking_limit'] as $k)if(isset($_POST[$k]))sa_set_setting($k,(string)$_POST[$k]);
                $msg='設定を保存しました。';break;
            case 'save_adult_words':
                $pdo->exec('DELETE FROM adult_words');$st=$pdo->prepare('INSERT IGNORE INTO adult_words(word) VALUES(?)');
                foreach(preg_split('/\R+/',adm_post('words')) as $w){$w=trim($w);if($w!=='')$st->execute([$w]);}
                $pdo->exec("UPDATE articles SET is_adult=CASE WHEN adult_mode=2 THEN 1 WHEN adult_mode=1 THEN 0 ELSE is_adult END");$msg='アダルトワードを保存しました。';break;
            case 'save_excluded':
                $pdo->exec('DELETE FROM excluded_urls');$st=$pdo->prepare('INSERT INTO excluded_urls(pattern,match_type) VALUES(?,\'host_suffix\')');
                foreach(preg_split('/\R+/',adm_post('patterns')) as $v){$v=trim($v);if($v!=='')$st->execute([$v]);}$msg='除外URLを保存しました。';break;
            case 'review_registration':
                $id=(int)$_POST['id'];$decision=adm_post('decision');$st=$pdo->prepare('SELECT * FROM registration_requests WHERE id=?');$st->execute([$id]);$r=$st->fetch();
                if(!$r)throw new RuntimeException('申請が見つかりません。');
                if($decision==='approved'){
                    $pdo->prepare('INSERT INTO sites(name,site_url,rss_url,category_id,status) VALUES(?,?,?,?,\'active\')')->execute([$r['site_name'],$r['site_url'],$r['rss_url'],$r['category_id']?:null]);
                }
                $pdo->prepare('UPDATE registration_requests SET status=?,reviewed_at=NOW() WHERE id=?')->execute([$decision==='approved'?'approved':'rejected',$id]);$msg='登録申請を処理しました。';break;
            case 'review_deletion':
                $id=(int)$_POST['id'];$status=adm_post('status');$pdo->prepare('UPDATE deletion_requests SET status=?,reviewed_at=NOW() WHERE id=?')->execute([$status,$id]);$msg='削除依頼を更新しました。';break;
            case 'save_page':
                $id=(int)($_POST['id']??0);$title=adm_post('title');$slug=adm_post('slug');$body=(string)($_POST['body']??'');$meta=adm_post('meta_description');$status=adm_post('status');
                if(!$title||!preg_match('/^[a-z0-9_-]+$/i',$slug))throw new RuntimeException('ページ情報を確認してください。');
                if($id)$pdo->prepare('UPDATE pages SET title=?,slug=?,body=?,meta_description=?,status=? WHERE id=?')->execute([$title,$slug,$body,$meta,$status,$id]);
                else $pdo->prepare('INSERT INTO pages(title,slug,body,meta_description,status) VALUES(?,?,?,?,?)')->execute([$title,$slug,$body,$meta,$status]);
                $msg='固定ページを保存しました。';break;
            case 'save_ad':
                $pos=adm_post('position_key');$label=adm_post('label');$html=(string)($_POST['html']??'');$enabled=isset($_POST['is_enabled'])?1:0;
                $pdo->prepare('INSERT INTO ads(position_key,label,html,is_enabled) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),html=VALUES(html),is_enabled=VALUES(is_enabled)')->execute([$pos,$label,$html,$enabled]);$msg='広告を保存しました。';break;
            case 'preferred':
                $id=(int)$_POST['id'];$on=isset($_POST['is_preferred'])?1:0;$pos=max(1,min(3,(int)($_POST['preferred_position']??1)));$pdo->prepare('UPDATE sites SET is_preferred=?,preferred_position=? WHERE id=?')->execute([$on,$on?$pos:null,$id]);$msg='優遇設定を保存しました。';break;
        }
    }catch(Throwable $e){$msg='エラー: '.$e->getMessage();}
}
$cats=$pdo->query('SELECT * FROM categories ORDER BY sort_order,name')->fetchAll();
function adm_csrf():string{return '<input type="hidden" name="_csrf" value="'.sa_e(sa_csrf_token()).'">';}
function adm_header(string $section,string $msg):void{?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SmartAntenna 管理</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><div class="admin-shell"><aside class="admin-side"><h2>SmartAntenna</h2><?php foreach(['dashboard'=>'ダッシュボード','sites'=>'サイト・RSS','articles'=>'記事','categories'=>'カテゴリ','rankings'=>'ランキング','analytics'=>'アクセス解析','requests'=>'申請・削除依頼','pages'=>'固定ページ','ads'=>'広告','adult'=>'アダルト判定','excluded'=>'除外URL','settings'=>'設定'] as $k=>$v):?><a href="?section=<?=$k?>"><?=sa_e($v)?></a><?php endforeach?><hr><a href="/" target="_blank">公開サイト</a><a href="/admin/logout.php">ログアウト</a></aside><main class="admin-main"><?php if($msg):?><div class="notice"><?=sa_e($msg)?></div><?php endif;?><h1><?=sa_e(ucfirst($section))?></h1><?php }
function adm_footer():void{?></main></div></body></html><?php }
adm_header($section,$msg);

if($section==='dashboard'){
 $counts=['サイト'=>$pdo->query("SELECT COUNT(*) FROM sites WHERE status='active'")->fetchColumn(),'公開記事'=>$pdo->query("SELECT COUNT(*) FROM articles WHERE is_deleted=0")->fetchColumn(),'登録申請'=>$pdo->query("SELECT COUNT(*) FROM registration_requests WHERE status='pending'")->fetchColumn(),'削除依頼'=>$pdo->query("SELECT COUNT(*) FROM deletion_requests WHERE status='pending'")->fetchColumn(),'IN 24h'=>$pdo->query("SELECT COUNT(*) FROM access_in WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn(),'OUT 24h'=>$pdo->query("SELECT COUNT(*) FROM access_out WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn()];
 echo '<div class="cards">';foreach($counts as $k=>$v)echo '<div class="card">'.sa_e($k).'<strong>'.sa_e($v).'</strong></div>';echo '</div><form method="post" style="margin-top:18px">'.adm_csrf().'<input type="hidden" name="action" value="refresh"><button class="btn">RSS・ランキングを今すぐ更新</button></form>';
}elseif($section==='sites'){
 $sites=$pdo->query('SELECT s.*,c.name category_name FROM sites s LEFT JOIN categories c ON c.id=s.category_id ORDER BY s.id DESC')->fetchAll();
 echo '<section class="panel"><h2>サイト追加</h2><form method="post">'.adm_csrf().'<input type="hidden" name="action" value="save_site"><div class="form-row"><label>サイト名</label><input name="name" required></div><div class="form-row"><label>サイトURL</label><input name="site_url" required></div><div class="form-row"><label>RSS URL</label><input name="rss_url" required></div><div class="form-row"><label>カテゴリ</label><select name="category_id"><option value="">なし</option>';foreach($cats as $c)echo '<option value="'.$c['id'].'">'.sa_e($c['name']).'</option>';echo '</select></div><input type="hidden" name="status" value="active"><button class="btn">登録</button></form></section>';
 echo '<div class="table-wrap"><table class="wp-table"><tr><th>サイト</th><th>RSS</th><th>取得</th><th>優遇</th><th>操作</th></tr>';foreach($sites as $s){echo '<tr><td>'.sa_e($s['name']).'<br><small>'.sa_e($s['site_url']).'</small></td><td>'.sa_e($s['rss_url']).'</td><td>'.sa_e($s['last_fetch_status']).'<br><small>'.sa_e($s['last_fetch_error']).'</small></td><td><form method="post">'.adm_csrf().'<input type="hidden" name="action" value="preferred"><input type="hidden" name="id" value="'.$s['id'].'"><label><input type="checkbox" name="is_preferred" '.($s['is_preferred']?'checked':'').'> 優遇</label><select name="preferred_position"><option>1</option><option>2</option><option>3</option></select><button>保存</button></form></td><td><form method="post" onsubmit="return confirm(\'削除扱いにしますか？\')">'.adm_csrf().'<input type="hidden" name="action" value="delete_site"><input type="hidden" name="id" value="'.$s['id'].'"><button class="btn btn-danger">削除</button></form></td></tr>';}echo '</table></div>';
}elseif($section==='articles'){
 $rows=$pdo->query('SELECT a.*,s.name site_name FROM articles a JOIN sites s ON s.id=a.site_id ORDER BY a.id DESC LIMIT 200')->fetchAll();echo '<div class="table-wrap"><table class="wp-table"><tr><th>ID</th><th>記事</th><th>サイト</th><th>判定</th><th>公開</th><th>保存</th></tr>';foreach($rows as $r){echo '<tr><form method="post">'.adm_csrf().'<input type="hidden" name="action" value="article_status"><input type="hidden" name="id" value="'.$r['id'].'"><input type="hidden" name="title" value="'.sa_e($r['title']).'"><td>'.$r['id'].'</td><td>'.sa_e($r['title']).'</td><td>'.sa_e($r['site_name']).'</td><td><select name="adult_mode"><option value="0" '.($r['adult_mode']==0?'selected':'').'>自動</option><option value="1" '.($r['adult_mode']==1?'selected':'').'>一般</option><option value="2" '.($r['adult_mode']==2?'selected':'').'>アダルト</option></select></td><td><select name="deleted"><option value="0" '.(!$r['is_deleted']?'selected':'').'>公開</option><option value="1" '.($r['is_deleted']?'selected':'').'>削除</option></select></td><td><button>保存</button></td></form></tr>';}echo '</table></div>';
}elseif($section==='categories'){
 echo '<section class="panel"><form method="post">'.adm_csrf().'<input type="hidden" name="action" value="save_category"><div class="inline"><input name="name" placeholder="カテゴリ名" required><input name="slug" placeholder="slug" required><label><input type="checkbox" name="is_adult"> アダルト</label><button class="btn">追加</button></div></form></section><table class="wp-table"><tr><th>ID</th><th>名前</th><th>slug</th><th>種別</th></tr>';foreach($cats as $c)echo '<tr><td>'.$c['id'].'</td><td>'.sa_e($c['name']).'</td><td>'.sa_e($c['slug']).'</td><td>'.($c['is_adult']?'アダルト':'一般').'</td></tr>';echo '</table>';
}elseif($section==='rankings'){
 foreach(['in24_site'=>'サイトIN 24時間','in7_site'=>'サイトIN 7日','out24'=>'記事OUT 24時間','out7'=>'記事OUT 7日','pv24'=>'記事PV 24時間','pv7'=>'記事PV 7日'] as $k=>$label){$st=$pdo->prepare('SELECT payload,generated_at FROM rank_cache WHERE cache_key=?');$st->execute([$k]);$r=$st->fetch();echo '<section class="panel"><h2>'.sa_e($label).'</h2><pre>'.sa_e($r['payload']??'[]').'</pre><small>'.sa_e($r['generated_at']??'未生成').'</small></section>';}
}elseif($section==='analytics'){
 echo '<section class="panel"><h2>未登録IN</h2><table class="wp-table"><tr><th>参照元</th><th>IN</th><th>最終</th></tr>';foreach($pdo->query("SELECT ref_host,COUNT(*) cnt,MAX(created_at) last_at FROM access_in WHERE site_id IS NULL GROUP BY ref_host ORDER BY cnt DESC LIMIT 100")->fetchAll() as $r)echo '<tr><td>'.sa_e($r['ref_host']).'</td><td>'.$r['cnt'].'</td><td>'.$r['last_at'].'</td></tr>';echo '</table></section>';
 echo '<section class="panel"><h2>不正疑い</h2><p>不正疑いフラグ付きアクセス: '.sa_e($pdo->query("SELECT (SELECT COUNT(*) FROM access_in WHERE is_fraud=1)+(SELECT COUNT(*) FROM access_out WHERE is_fraud=1)+(SELECT COUNT(*) FROM article_pv WHERE is_fraud=1)")->fetchColumn()).'</p></section>';
}elseif($section==='requests'){
 echo '<section class="panel"><h2>サイト登録申請</h2><table class="wp-table"><tr><th>サイト</th><th>連絡先</th><th>状態</th><th>操作</th></tr>';foreach($pdo->query('SELECT * FROM registration_requests ORDER BY id DESC LIMIT 200')->fetchAll() as $r){echo '<tr><td>'.sa_e($r['site_name']).'<br>'.sa_e($r['site_url']).'<br>'.sa_e($r['rss_url']).'</td><td>'.sa_e($r['email']).'</td><td>'.sa_e($r['status']).'</td><td>';if($r['status']==='pending')echo '<form method="post">'.adm_csrf().'<input type="hidden" name="action" value="review_registration"><input type="hidden" name="id" value="'.$r['id'].'"><button name="decision" value="approved">承認</button> <button name="decision" value="rejected">却下</button></form>';echo '</td></tr>';}echo '</table></section>';
 echo '<section class="panel"><h2>削除依頼</h2><table class="wp-table"><tr><th>記事</th><th>理由</th><th>状態</th><th>操作</th></tr>';foreach($pdo->query('SELECT * FROM deletion_requests ORDER BY id DESC LIMIT 200')->fetchAll() as $r){echo '<tr><td>'.sa_e($r['article_id']).'</td><td>'.sa_e($r['reason']).'<br>'.nl2br(sa_e($r['body'])).'</td><td>'.sa_e($r['status']).'</td><td><form method="post">'.adm_csrf().'<input type="hidden" name="action" value="review_deletion"><input type="hidden" name="id" value="'.$r['id'].'"><select name="status"><option>pending</option><option>done</option><option>rejected</option></select><button>保存</button></form></td></tr>';}echo '</table></section>';
}elseif($section==='pages'){
 echo '<section class="panel"><h2>固定ページ追加</h2><form method="post">'.adm_csrf().'<input type="hidden" name="action" value="save_page"><div class="form-row"><label>タイトル</label><input name="title" required></div><div class="form-row"><label>slug</label><input name="slug" required></div><div class="form-row"><label>本文</label><textarea name="body" rows="8" required></textarea></div><div class="form-row"><label>Meta Description</label><input name="meta_description"></div><input type="hidden" name="status" value="published"><button class="btn">追加</button></form></section>';
 echo '<table class="wp-table"><tr><th>タイトル</th><th>slug</th><th>状態</th></tr>';foreach($pdo->query('SELECT * FROM pages ORDER BY id DESC')->fetchAll() as $r)echo '<tr><td>'.sa_e($r['title']).'</td><td>'.sa_e($r['slug']).'</td><td>'.sa_e($r['status']).'</td></tr>';echo '</table>';
}elseif($section==='ads'){
 echo '<section class="panel"><form method="post">'.adm_csrf().'<input type="hidden" name="action" value="save_ad"><div class="form-row"><label>位置キー</label><select name="position_key"><option>header</option><option>sidebar</option><option>between_new_recommended</option><option>between_recommended_popular</option><option>mobile_header</option></select></div><div class="form-row"><label>名称</label><input name="label" required></div><div class="form-row"><label>広告HTML</label><textarea name="html" rows="8"></textarea></div><label><input type="checkbox" name="is_enabled"> 有効</label> <button class="btn">保存</button></form></section><table class="wp-table"><tr><th>位置</th><th>名称</th><th>有効</th></tr>';foreach($pdo->query('SELECT * FROM ads ORDER BY id')->fetchAll() as $r)echo '<tr><td>'.sa_e($r['position_key']).'</td><td>'.sa_e($r['label']).'</td><td>'.($r['is_enabled']?'有効':'無効').'</td></tr>';echo '</table>';
}elseif($section==='adult'){
 $words=implode("\n",array_column($pdo->query('SELECT word FROM adult_words ORDER BY word')->fetchAll(),'word'));echo '<section class="panel"><form method="post">'.adm_csrf().'<input type="hidden" name="action" value="save_adult_words"><div class="form-row"><label>アダルトワード（1行1語）</label><textarea name="words" rows="18">'.sa_e($words).'</textarea></div><button class="btn">保存</button></form></section>';
}elseif($section==='excluded'){
 $patterns=implode("\n",array_column($pdo->query('SELECT pattern FROM excluded_urls ORDER BY id')->fetchAll(),'pattern'));echo '<section class="panel"><form method="post">'.adm_csrf().'<input type="hidden" name="action" value="save_excluded"><div class="form-row"><label>IN除外ホスト（1行1件）</label><textarea name="patterns" rows="15">'.sa_e($patterns).'</textarea></div><button class="btn">保存</button></form></section>';
}else{
 echo '<section class="panel"><form method="post">'.adm_csrf().'<input type="hidden" name="action" value="save_settings"><div class="form-row"><label>サイト名</label><input name="site_name" value="'.sa_e(sa_setting('site_name','SmartAntenna')).'"></div><div class="form-row"><label>RSS更新間隔</label><select name="refresh_interval">';foreach([10,30,60,120,180,360] as $v)echo '<option value="'.$v.'" '.(sa_setting('refresh_interval')==$v?'selected':'').'>'.$v.'分</option>';echo '</select></div><div class="form-row"><label>記事保存期間</label><select name="article_retention">';foreach(['1'=>'1年','2'=>'2年','3'=>'3年','5'=>'5年','unlimited'=>'無期限'] as $v=>$l)echo '<option value="'.$v.'" '.(sa_setting('article_retention')===$v?'selected':'').'>'.$l.'</option>';echo '</select></div><div class="form-row"><label>ランキング表示数</label><select name="ranking_limit">';foreach([100,200,300] as $v)echo '<option '.(sa_setting('ranking_limit')==$v?'selected':'').'>'.$v.'</option>';echo '</select></div><button class="btn">保存</button></form></section>';
}
adm_footer();