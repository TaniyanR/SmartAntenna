CREATE TABLE IF NOT EXISTS schema_meta (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_meta (id, version) VALUES (1,1) ON DUPLICATE KEY UPDATE version=VALUES(version);

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  login_failures INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  is_adult TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sites (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  site_url VARCHAR(2048) NOT NULL,
  rss_url VARCHAR(2048) NOT NULL,
  category_id INT UNSIGNED NULL,
  status ENUM('active','paused','deleted') NOT NULL DEFAULT 'active',
  is_preferred TINYINT(1) NOT NULL DEFAULT 0,
  preferred_position TINYINT UNSIGNED NULL,
  last_fetch_at DATETIME NULL,
  last_fetch_status VARCHAR(50) NULL,
  last_fetch_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sites_status(status),
  INDEX idx_sites_category(category_id),
  CONSTRAINT fk_sites_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_aliases (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  alias_host VARCHAR(255) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_alias_site(site_id),
  CONSTRAINT fk_alias_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS articles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NULL,
  title VARCHAR(500) NOT NULL,
  original_url VARCHAR(2048) NOT NULL,
  normalized_url VARCHAR(2048) NOT NULL,
  url_hash CHAR(64) NOT NULL UNIQUE,
  image_url VARCHAR(2048) NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  adult_mode TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_adult TINYINT(1) NOT NULL DEFAULT 0,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  deleted_reason VARCHAR(100) NULL,
  deleted_at DATETIME NULL,
  INDEX idx_articles_site(site_id),
  INDEX idx_articles_category(category_id),
  INDEX idx_articles_published(published_at),
  INDEX idx_articles_created(created_at),
  INDEX idx_articles_deleted(is_deleted),
  INDEX idx_articles_adult(is_adult),
  CONSTRAINT fk_articles_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_articles_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deleted_articles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  url_hash CHAR(64) NOT NULL UNIQUE,
  original_url VARCHAR(2048) NOT NULL,
  reason VARCHAR(100) NULL,
  deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_in (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NULL,
  ref_host VARCHAR(255) NULL,
  ref_url VARCHAR(2048) NULL,
  ip_hash CHAR(64) NOT NULL,
  ua_hash CHAR(64) NOT NULL,
  is_direct TINYINT(1) NOT NULL DEFAULT 0,
  is_fraud TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_in_site_created(site_id,created_at),
  INDEX idx_in_ref_created(ref_host,created_at),
  INDEX idx_in_fraud(is_fraud)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS article_pv (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  article_id BIGINT UNSIGNED NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  ua_hash CHAR(64) NOT NULL,
  is_fraud TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pv_article_created(article_id,created_at),
  CONSTRAINT fk_pv_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_out (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  article_id BIGINT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  ua_hash CHAR(64) NOT NULL,
  is_fraud TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_out_article_created(article_id,created_at),
  INDEX idx_out_site_created(site_id,created_at),
  CONSTRAINT fk_out_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
  CONSTRAINT fk_out_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(50) NOT NULL,
  article_id BIGINT UNSIGNED NULL,
  page_type VARCHAR(50) NULL,
  target VARCHAR(255) NULL,
  ip_hash CHAR(64) NOT NULL,
  ua_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_event_type_created(event_type,created_at),
  INDEX idx_event_article(article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS excluded_urls (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pattern VARCHAR(500) NOT NULL,
  match_type ENUM('exact','host_suffix') NOT NULL DEFAULT 'host_suffix',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS adult_words (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  word VARCHAR(255) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rank_cache (
  cache_key VARCHAR(100) NOT NULL PRIMARY KEY,
  payload MEDIUMTEXT NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS registration_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  site_name VARCHAR(200) NOT NULL,
  site_url VARCHAR(2048) NOT NULL,
  rss_url VARCHAR(2048) NOT NULL,
  category_id INT UNSIGNED NULL,
  contact_name VARCHAR(200) NULL,
  email VARCHAR(255) NOT NULL,
  note TEXT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_registration_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deletion_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  article_id BIGINT UNSIGNED NULL,
  site_id INT UNSIGNED NULL,
  reason VARCHAR(255) NOT NULL,
  contact VARCHAR(255) NULL,
  body TEXT NULL,
  requester_ip VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  status ENUM('pending','done','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  INDEX idx_deletion_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(150) NOT NULL UNIQUE,
  body MEDIUMTEXT NOT NULL,
  meta_description VARCHAR(500) NULL,
  status ENUM('published','draft','trash') NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pages_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  position_key VARCHAR(100) NOT NULL UNIQUE,
  label VARCHAR(150) NOT NULL,
  html MEDIUMTEXT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings(setting_key,setting_value) VALUES
('site_name','SmartAntenna'),
('refresh_interval','60'),
('article_retention','unlimited'),
('ranking_limit','100'),
('timezone','Asia/Tokyo'),
('last_refresh_at','0'),
('last_cleanup_at','0');

INSERT IGNORE INTO pages(title,slug,body,meta_description,status) VALUES
('サイトについて','about','SmartAntennaについての案内ページです。','SmartAntennaについて','published'),
('プライバシーポリシー','privacy-policy','プライバシーポリシーを記載してください。','プライバシーポリシー','published'),
('サイト登録','site-registration','サイト登録は専用フォームから申請してください。','サイト登録について','published'),
('登録・削除について','registration-delete','登録・削除に関する案内を記載してください。','登録・削除について','published');
