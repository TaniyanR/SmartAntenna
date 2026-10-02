<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(is_file($root.'/.env')){header('Location: /admin/login0929.php');exit;}
$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $host=trim((string)($_POST['db_host']??'127.0.0.1')); $port=trim((string)($_POST['db_port']??'3306'));
    $name=trim((string)($_POST['db_name']??'')); $user=trim((string)($_POST['db_user']??'')); $pass=(string)($_POST['db_pass']??'');
    $appUrl=rtrim(trim((string)($_POST['app_url']??'')),'/'); $siteName=trim((string)($_POST['site_name']??'SmartAntenna'));
    $admin=trim((string)($_POST['admin_user']??'admin')); $adminPass=(string)($_POST['admin_pass']??'');
    try{
        if(!$name||!$user||!$appUrl||!$admin||strlen($adminPass)<12) throw new RuntimeException('必須項目を確認してください。管理者パスワードは12文字以上にしてください。');
        $pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        $sql=file_get_contents($root.'/database/schema.sql'); if($sql===false) throw new RuntimeException('schema.sqlを読み込めません。');
        foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $q){$q=trim($q);if($q!=='')$pdo->exec($q);}
        $key=bin2hex(random_bytes(32));
        $env="APP_ENV=production\nAPP_DEBUG=0\nAPP_URL={$appUrl}\nAPP_NAME={$siteName}\nAPP_TIMEZONE=Asia/Tokyo\nAPP_KEY={$key}\n\nDB_HOST={$host}\nDB_PORT={$port}\nDB_NAME={$name}\nDB_USER={$user}\nDB_PASS=".str_replace(["\r","\n"],'',$pass)."\n";
        if(file_put_contents($root.'/.env',$env,LOCK_EX)===false) throw new RuntimeException('.envを書き込めません。ルートディレクトリの権限を確認してください。');
        $st=$pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=\'site_name\'');$st->execute([$siteName]);
        $st=$pdo->prepare('INSERT INTO admins(username,password_hash) VALUES(?,?)');$st->execute([$admin,password_hash($adminPass,PASSWORD_DEFAULT)]);
        header('Location: /admin/login0929.php?installed=1');exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SmartAntenna インストール</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="container" style="max-width:760px"><section class="panel"><h1>SmartAntenna インストール</h1><?php if($error):?><div class="notice error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif?><form method="post">
<div class="form-row"><label>サイト名</label><input name="site_name" value="SmartAntenna" required></div>
<div class="form-row"><label>公開URL</label><input name="app_url" placeholder="https://example.com" required></div>
<div class="form-row"><label>DBホスト</label><input name="db_host" value="127.0.0.1" required></div>
<div class="form-row"><label>DBポート</label><input name="db_port" value="3306" required></div>
<div class="form-row"><label>DB名</label><input name="db_name" required></div>
<div class="form-row"><label>DBユーザー</label><input name="db_user" required></div>
<div class="form-row"><label>DBパスワード</label><input type="password" name="db_pass"></div>
<div class="form-row"><label>管理ユーザー名</label><input name="admin_user" value="admin" required></div>
<div class="form-row"><label>管理パスワード（12文字以上）</label><input type="password" name="admin_pass" minlength="12" required></div>
<button class="btn" type="submit">インストール</button></form></section></main></body></html>