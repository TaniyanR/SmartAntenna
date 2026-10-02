<?php
declare(strict_types=1);

const SA_ROOT = __DIR__ . '/..';

function sa_load_env(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k,$v] = array_map('trim', explode('=', $line, 2));
        if (!array_key_exists($k, $_ENV)) $_ENV[$k] = trim($v, "'\"");
    }
}
sa_load_env(SA_ROOT.'/.env');

function sa_env(string $key, mixed $default=null): mixed { return $_ENV[$key] ?? getenv($key) ?: $default; }
date_default_timezone_set((string)sa_env('APP_TIMEZONE','Asia/Tokyo'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly'=>true,
        'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite'=>'Lax',
        'path'=>'/'
    ]);
    session_start();
}

function sa_security_headers(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self' https:; img-src 'self' https: data:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:; frame-src https:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
sa_security_headers();

function sa_db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dsn='mysql:host='.sa_env('DB_HOST','127.0.0.1').';port='.sa_env('DB_PORT','3306').';dbname='.sa_env('DB_NAME','smartantenna').';charset=utf8mb4';
    $pdo=new PDO($dsn,(string)sa_env('DB_USER','root'),(string)sa_env('DB_PASS',''),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    return $pdo;
}

function sa_e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function sa_url(string $path=''): string { return rtrim((string)sa_env('APP_URL',''),'/').'/'.ltrim($path,'/'); }
function sa_is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function sa_redirect(string $url, int $code=302): never { header('Location: '.$url,true,$code); exit; }
function sa_now(): string { return date('Y-m-d H:i:s'); }

function sa_setting(string $key, mixed $default=null): mixed {
    static $cache=[];
    if (array_key_exists($key,$cache)) return $cache[$key];
    try {
        $st=sa_db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $st->execute([$key]);
        $v=$st->fetchColumn();
        return $cache[$key]=$v!==false?$v:$default;
    } catch(Throwable) { return $default; }
}
function sa_set_setting(string $key, string $value): void {
    $st=sa_db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $st->execute([$key,$value]);
}

function sa_csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function sa_verify_csrf(): void {
    $got=(string)($_POST['_csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''),$got)) { http_response_code(403); exit('Invalid CSRF token'); }
}
function sa_honeypot_ok(): bool { return empty($_POST['website'] ?? ''); }

function sa_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,64); }
function sa_ua(): string { return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500); }
function sa_hash_client(string $value): string { return hash_hmac('sha256',$value,(string)sa_env('APP_KEY','smartantenna')); }
function sa_ip_hash(): string { return sa_hash_client(sa_ip()); }
function sa_ua_hash(): string { return sa_hash_client(sa_ua()); }

function sa_normalize_url(string $url): string {
    $url=trim($url);
    $p=parse_url($url);
    if (!$p || empty($p['host'])) return $url;
    $scheme=strtolower($p['scheme'] ?? 'https');
    $host=strtolower($p['host']);
    if (str_starts_with($host,'www.')) $host=substr($host,4);
    $port=isset($p['port']) && !(($scheme==='http'&&$p['port']===80)||($scheme==='https'&&$p['port']===443)) ? ':'.$p['port'] : '';
    $path=$p['path'] ?? '/';
    if ($path!=='/') $path=rtrim($path,'/');
    $query=[];
    if (!empty($p['query'])) {
        parse_str($p['query'],$query);
        foreach (array_keys($query) as $k) if (preg_match('/^(utm_|fbclid$|gclid$)/i',$k)) unset($query[$k]);
        ksort($query);
    }
    return 'https://'.$host.$port.$path.($query?'?'.http_build_query($query):'');
}

function sa_is_private_ip(string $ip): bool {
    return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false;
}
function sa_validate_remote_url(string $url): bool {
    $p=parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme']??''),['http','https'],true) || empty($p['host'])) return false;
    $host=strtolower($p['host']);
    if ($host==='localhost' || str_ends_with($host,'.local')) return false;
    $ips=gethostbynamel($host) ?: [];
    if (!$ips) return false;
    foreach($ips as $ip) if (sa_is_private_ip($ip)) return false;
    return true;
}

function sa_fetch_url(string $url): string {
    if (!sa_validate_remote_url($url)) throw new RuntimeException('Blocked RSS URL');
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>3,
        CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>12, CURLOPT_USERAGENT=>'SmartAntenna/1.0',
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
    ]);
    $body=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $err=curl_error($ch); curl_close($ch);
    if ($body===false || $code<200 || $code>=300) throw new RuntimeException('HTTP '.$code.' '.$err);
    if (strlen($body)>5*1024*1024) throw new RuntimeException('RSS too large');
    return $body;
}

function sa_classify_adult(string $title): int {
    $st=sa_db()->query('SELECT word FROM adult_words');
    foreach($st->fetchAll() as $r) if ($r['word']!=='' && mb_stripos($title,$r['word'])!==false) return 1;
    return 0;
}

function sa_parse_date(?string $value): ?string {
    if (!$value) return null;
    $t=strtotime($value);
    return $t?date('Y-m-d H:i:s',$t):null;
}

function sa_fetch_site(array $site): array {
    $xml=sa_fetch_url($site['rss_url']);
    libxml_use_internal_errors(true);
    $dom=new DOMDocument();
    if (!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) throw new RuntimeException('Invalid XML');
    $xp=new DOMXPath($dom);
    $items=$xp->query('//*[local-name()="item"]');
    $isAtom=false;
    if (!$items || $items->length===0) { $items=$xp->query('//*[local-name()="entry"]'); $isAtom=true; }
    $count=0;
    foreach ($items ?: [] as $item) {
        if ($count>=100) break;
        $title=trim((string)$xp->evaluate('string(.//*[local-name()="title"][1])',$item));
        $link='';
        if ($isAtom) {
            foreach($xp->query('.//*[local-name()="link"]',$item) ?: [] as $ln) {
                $rel=$ln->attributes?->getNamedItem('rel')?->nodeValue ?? '';
                $href=$ln->attributes?->getNamedItem('href')?->nodeValue ?? '';
                if ($href && ($rel===''||$rel==='alternate')) { $link=$href; break; }
            }
        } else $link=trim((string)$xp->evaluate('string(.//*[local-name()="link"][1])',$item));
        if ($title==='' || $link==='') continue;
        $normalized=sa_normalize_url($link); $hash=hash('sha256',$normalized);
        $tomb=sa_db()->prepare('SELECT 1 FROM deleted_articles WHERE url_hash=?'); $tomb->execute([$hash]);
        if ($tomb->fetchColumn()) continue;
        $date=(string)$xp->evaluate('string(.//*[local-name()="pubDate"][1])',$item);
        if ($date==='') $date=(string)$xp->evaluate('string(.//*[local-name()="published"][1])',$item);
        if ($date==='') $date=(string)$xp->evaluate('string(.//*[local-name()="updated"][1])',$item);
        $image='';
        foreach($xp->query('.//*[local-name()="thumbnail" or local-name()="content" or local-name()="enclosure"]',$item) ?: [] as $n) {
            $u=$n->attributes?->getNamedItem('url')?->nodeValue ?? '';
            if ($u) { $image=$u; break; }
        }
        $st=sa_db()->prepare('INSERT IGNORE INTO articles(site_id,category_id,title,original_url,normalized_url,url_hash,image_url,published_at,is_adult) VALUES(?,?,?,?,?,?,?,?,?)');
        $st->execute([$site['id'],$site['category_id'],$title,$link,$normalized,$hash,$image?:null,sa_parse_date($date),sa_classify_adult($title)]);
        $count++;
    }
    $st=sa_db()->prepare("UPDATE sites SET last_fetch_at=NOW(),last_fetch_status='ok',last_fetch_error=NULL WHERE id=?");
    $st->execute([$site['id']]);
    return ['count'=>$count];
}

function sa_refresh_rss(): void {
    $sites=sa_db()->query("SELECT * FROM sites WHERE status='active' ORDER BY id")->fetchAll();
    foreach($sites as $site){
        try { sa_fetch_site($site); }
        catch(Throwable $e){
            $st=sa_db()->prepare("UPDATE sites SET last_fetch_at=NOW(),last_fetch_status='error',last_fetch_error=? WHERE id=?");
            $st->execute([mb_substr($e->getMessage(),0,1000),$site['id']]);
            sa_log('rss','Site '.$site['id'].': '.$e->getMessage());
        }
    }
}

function sa_refresh_rankings(): void {
    $defs=[
      'out24'=>["SELECT article_id id,COUNT(*) score FROM access_out WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND is_fraud=0 GROUP BY article_id ORDER BY score DESC LIMIT 300"],
      'out7'=>["SELECT article_id id,COUNT(*) score FROM access_out WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND is_fraud=0 GROUP BY article_id ORDER BY score DESC LIMIT 300"],
      'pv24'=>["SELECT article_id id,COUNT(*) score FROM article_pv WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND is_fraud=0 GROUP BY article_id ORDER BY score DESC LIMIT 300"],
      'pv7'=>["SELECT article_id id,COUNT(*) score FROM article_pv WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND is_fraud=0 GROUP BY article_id ORDER BY score DESC LIMIT 300"],
      'in24_site'=>["SELECT site_id id,COUNT(*) score FROM access_in WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND site_id IS NOT NULL AND is_fraud=0 GROUP BY site_id ORDER BY score DESC LIMIT 300"],
      'in7_site'=>["SELECT site_id id,COUNT(*) score FROM access_in WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND site_id IS NOT NULL AND is_fraud=0 GROUP BY site_id ORDER BY score DESC LIMIT 300"],
    ];
    $up=sa_db()->prepare('INSERT INTO rank_cache(cache_key,payload,generated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE payload=VALUES(payload),generated_at=NOW()');
    foreach($defs as $key=>$v) $up->execute([$key,json_encode(sa_db()->query($v[0])->fetchAll(),JSON_UNESCAPED_UNICODE)]);
}

function sa_cleanup_expired(): void {
    $ret=(string)sa_setting('article_retention','unlimited');
    if ($ret==='unlimited') return;
    $years=max(1,(int)$ret);
    $cutoff=date('Y-m-d H:i:s',strtotime('-'.$years.' years'));
    $st=sa_db()->prepare("UPDATE articles SET is_deleted=1,deleted_reason='期限切れ',deleted_at=NOW() WHERE is_deleted=0 AND COALESCE(published_at,created_at)<?");
    $st->execute([$cutoff]);
}

function sa_maybe_refresh(): void {
    try {
        $interval=max(10,(int)sa_setting('refresh_interval','60'));
        $last=(int)sa_setting('last_refresh_at','0');
        if (time()-$last < $interval*60) return;
        $dir=SA_ROOT.'/storage/locks'; if (!is_dir($dir)) @mkdir($dir,0775,true);
        $fp=@fopen($dir.'/refresh.lock','c');
        if (!$fp || !flock($fp,LOCK_EX|LOCK_NB)) return;
        $last=(int)sa_setting('last_refresh_at','0');
        if (time()-$last >= $interval*60) {
            sa_set_setting('last_refresh_at',(string)time());
            sa_refresh_rss(); sa_refresh_rankings();
            $clean=(int)sa_setting('last_cleanup_at','0');
            if (time()-$clean>=86400) { sa_cleanup_expired(); sa_set_setting('last_cleanup_at',(string)time()); }
        }
        flock($fp,LOCK_UN); fclose($fp);
    } catch(Throwable $e) { sa_log('refresh',$e->getMessage()); }
}

function sa_log(string $type,string $message): void {
    $dir=SA_ROOT.'/storage/logs'; if (!is_dir($dir)) @mkdir($dir,0775,true);
    @file_put_contents($dir.'/app.log',date('c').' ['.$type.'] '.$message.PHP_EOL,FILE_APPEND|LOCK_EX);
}

function sa_find_site_by_host(string $host): ?int {
    $host=strtolower(preg_replace('/^www\./','',$host));
    $st=sa_db()->query("SELECT id,site_url FROM sites WHERE status='active'");
    foreach($st->fetchAll() as $s) {
        $h=strtolower(preg_replace('/^www\./','',(string)parse_url($s['site_url'],PHP_URL_HOST)));
        if ($host===$h || str_ends_with($host,'.'.$h)) return (int)$s['id'];
    }
    $st=sa_db()->prepare('SELECT site_id FROM site_aliases WHERE alias_host=?'); $st->execute([$host]);
    $v=$st->fetchColumn(); return $v!==false?(int)$v:null;
}

function sa_ref_excluded(string $ref): bool {
    $host=strtolower((string)parse_url($ref,PHP_URL_HOST));
    foreach(sa_db()->query('SELECT pattern,match_type FROM excluded_urls')->fetchAll() as $r){
        $p=strtolower(trim($r['pattern']));
        if ($r['match_type']==='exact' && strtolower($ref)===$p) return true;
        if ($r['match_type']==='host_suffix' && ($host===$p || str_ends_with($host,'.'.$p))) return true;
    }
    return false;
}

function sa_is_suspicious(string $kind): int {
    $ua=sa_ua();
    if ($ua==='' || preg_match('/(?:curl|wget|python|scrapy|httpclient|headless)/i',$ua)) return 1;
    try {
        $table=$kind==='out'?'access_out':($kind==='pv'?'article_pv':'access_in');
        $st=sa_db()->prepare("SELECT COUNT(*) FROM {$table} WHERE ip_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)");
        $st->execute([sa_ip_hash()]);
        return (int)$st->fetchColumn()>120 ? 1 : 0;
    } catch(Throwable) { return 0; }
}
function sa_rate_limit(string $key,int $seconds=30): bool {
    $k='rate_'.$key; $last=(int)($_SESSION[$k]??0);
    if(time()-$last<$seconds) return false;
    $_SESSION[$k]=time(); return true;
}

function sa_track_in(): void {
    $ref=(string)($_SERVER['HTTP_REFERER'] ?? '');
    $self=strtolower((string)parse_url(sa_env('APP_URL',''),PHP_URL_HOST));
    $refHost=strtolower((string)parse_url($ref,PHP_URL_HOST));
    if ($ref!=='' && ($refHost===$self || ($self && str_ends_with($refHost,'.'.$self)))) return;
    if ($ref!=='' && sa_ref_excluded($ref)) return;
    $direct=$ref===''?1:0; $siteId=$direct?null:sa_find_site_by_host($refHost);
    $ip=sa_ip_hash(); $ua=sa_ua_hash();
    $st=sa_db()->prepare('SELECT 1 FROM access_in WHERE ip_hash=? AND ua_hash=? AND ((site_id IS NULL AND ? IS NULL) OR site_id=?) AND created_at>=DATE_SUB(NOW(),INTERVAL 12 HOUR) LIMIT 1');
    $st->execute([$ip,$ua,$siteId,$siteId]); if ($st->fetchColumn()) return;
    $st=sa_db()->prepare('INSERT INTO access_in(site_id,ref_host,ref_url,ip_hash,ua_hash,is_direct,is_fraud) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$siteId,$direct?'Direct / Bookmark':$refHost,$ref?:null,$ip,$ua,$direct,sa_is_suspicious('in')]);
}

function sa_track_pv(int $articleId): void {
    $ip=sa_ip_hash(); $ua=sa_ua_hash();
    $st=sa_db()->prepare('SELECT 1 FROM article_pv WHERE article_id=? AND ip_hash=? AND ua_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)');
    $st->execute([$articleId,$ip,$ua]); if($st->fetchColumn()) return;
    $st=sa_db()->prepare('INSERT INTO article_pv(article_id,ip_hash,ua_hash,is_fraud) VALUES(?,?,?,?)'); $st->execute([$articleId,$ip,$ua,sa_is_suspicious('pv')]);
}
function sa_track_out(array $article): void {
    $ip=sa_ip_hash(); $ua=sa_ua_hash();
    $st=sa_db()->prepare('SELECT 1 FROM access_out WHERE article_id=? AND ip_hash=? AND ua_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)');
    $st->execute([$article['id'],$ip,$ua]); if(!$st->fetchColumn()) {
        $st=sa_db()->prepare('INSERT INTO access_out(article_id,site_id,ip_hash,ua_hash,is_fraud) VALUES(?,?,?,?,?)');
        $st->execute([$article['id'],$article['site_id'],$ip,$ua,sa_is_suspicious('out')]);
    }
}

function sa_article(int $id): ?array {
    $st=sa_db()->prepare('SELECT a.*,s.name site_name,s.status site_status,c.name category_name,c.slug category_slug FROM articles a JOIN sites s ON s.id=a.site_id LEFT JOIN categories c ON c.id=a.category_id WHERE a.id=?');
    $st->execute([$id]); $r=$st->fetch(); return $r?:null;
}
function sa_articles(string $where='1=1', array $params=[], int $limit=50): array {
    $sql='SELECT a.*,s.name site_name,c.name category_name,c.slug category_slug FROM articles a JOIN sites s ON s.id=a.site_id LEFT JOIN categories c ON c.id=a.category_id WHERE a.is_deleted=0 AND s.status=\'active\' AND '.$where.' ORDER BY COALESCE(a.published_at,a.created_at) DESC LIMIT '.max(1,min(300,$limit));
    $st=sa_db()->prepare($sql); $st->execute($params); return $st->fetchAll();
}
function sa_rank_articles(string $key,int $limit=20): array {
    $st=sa_db()->prepare('SELECT payload FROM rank_cache WHERE cache_key=?');$st->execute([$key]);
    $data=json_decode((string)$st->fetchColumn(),true) ?: []; $ids=array_slice(array_map(fn($x)=>(int)$x['id'],$data),0,$limit);
    if(!$ids) return [];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $st=sa_db()->prepare("SELECT a.*,s.name site_name FROM articles a JOIN sites s ON s.id=a.site_id WHERE a.is_deleted=0 AND a.id IN ($ph)");
    $st->execute($ids); $rows=$st->fetchAll(); $map=[]; foreach($rows as $r)$map[(int)$r['id']]=$r;
    $out=[]; foreach($ids as $id) if(isset($map[$id]))$out[]=$map[$id]; return $out;
}

function sa_preferred_articles(int $limit=3): array {
    $st=sa_db()->prepare("SELECT a.*,s.name site_name FROM sites s JOIN articles a ON a.site_id=s.id WHERE s.status='active' AND s.is_preferred=1 AND a.is_deleted=0 ORDER BY COALESCE(s.preferred_position,99),COALESCE(a.published_at,a.created_at) DESC LIMIT ".max(1,min(3,$limit)));
    $st->execute(); return $st->fetchAll();
}

function sa_admin_logged_in(): bool { return !empty($_SESSION['admin_id']); }
function sa_require_admin(): void { if(!sa_admin_logged_in()) sa_redirect(sa_url('/admin/login0929.php')); }

function sa_page_title(string $title=''): string {
    $site=(string)sa_setting('site_name',sa_env('APP_NAME','SmartAntenna'));
    return $title!==''?$title.' | '.$site:$site;
}
