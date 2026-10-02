<?php
declare(strict_types=1);
if(!is_file(dirname(__DIR__,2).'/.env')){header('Location: /install.php');exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
if(sa_admin_logged_in()) sa_redirect(sa_url('/admin/'));
$error='';
if(sa_is_post()){
    sa_verify_csrf();
    $u=trim((string)($_POST['username']??''));$p=(string)($_POST['password']??'');
    $st=sa_db()->prepare('SELECT * FROM admins WHERE username=?');$st->execute([$u]);$a=$st->fetch();
    if($a && $a['locked_until'] && strtotime($a['locked_until'])>time()){$error='一時的にログインを制限しています。';http_response_code(429);}
    elseif($a && password_verify($p,$a['password_hash'])){
        sa_db()->prepare('UPDATE admins SET login_failures=0,locked_until=NULL WHERE id=?')->execute([$a['id']]);
        session_regenerate_id(true);$_SESSION['admin_id']=(int)$a['id'];$_SESSION['admin_name']=$a['username'];sa_redirect(sa_url('/admin/'));
    }else{
        if($a){$fails=(int)$a['login_failures']+1;$lock=$fails>=5?date('Y-m-d H:i:s',time()+900):null;sa_db()->prepare('UPDATE admins SET login_failures=?,locked_until=? WHERE id=?')->execute([$fails,$lock,$a['id']]);}
        usleep(350000);$error='ユーザー名またはパスワードが違います。';
    }
}
?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>管理ログイン</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="container" style="max-width:480px"><section class="panel"><h1>SmartAntenna 管理ログイン</h1><?php if(isset($_GET['installed'])):?><div class="notice">インストールが完了しました。</div><?php endif?><?php if($error):?><div class="notice error"><?=sa_e($error)?></div><?php endif?><form method="post"><input type="hidden" name="_csrf" value="<?=sa_e(sa_csrf_token())?>"><div class="form-row"><label>ユーザー名</label><input name="username" autocomplete="username" required></div><div class="form-row"><label>パスワード</label><input type="password" name="password" autocomplete="current-password" required></div><button class="btn">ログイン</button></form></section></main></body></html>