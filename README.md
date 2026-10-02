# SmartAntenna

SmartAntenna は、PHP + MySQL/MariaDB で動作する高性能・軽量なRSSアンテナサイトシステムです。

RSS収集、記事中継、IN/PV/OUT計測、ランキング、一般/アダルト分類、RSS再配信、サイト登録申請、削除依頼、固定ページ・広告管理までを一体化しています。

## 基本方針

- PHP 8系 / MySQL または MariaDB / PDO
- HTML5 / 素のCSS / 必要最小限のJavaScript
- cron不要。アクセス契機の `maybe_refresh()` 方式
- RSS更新間隔: 10 / 30 / 60 / 120 / 180 / 360分
- Google Search Console / Bing Webmaster Tools への登録は前提にしない
- ただし Google / Bing 双方に対する通常のSEO対策を実施
- W3C、レスポンシブ、アクセシビリティ、表示速度を重視
- SQL Injection / XSS / CSRF / SSRF / XXE / Open Redirect 等を考慮
- 管理画面は認証必須
- DBは初回セットアップで自動展開
- 大規模フレームワークや不要な外部依存を使わない

## アクセス構造

```text
外部登録サイト
  ↓ IN
SmartAntenna
  ↓
記事一覧
  ↓
記事中継ページ（PV）
  ↓ OUT
元記事
```

記事一覧から外部記事へ直接飛ばさず、SmartAntenna 内の記事中継ページを1ページ挟みます。

## 実装済み機能

### RSS・記事

- RSS 2.0 / Atom
- `description` / `content:encoded` 系RSSの基本構造に対応
- `media:content` / `media:thumbnail` / `enclosure` から画像URL取得
- 1サイト最大100記事程度を取得
- RSS取得失敗時も他サイト・公開画面・管理画面を停止させない
- URL正規化
- URLハッシュによる重複防止
- 削除済み記事の再登録抑止
- 記事保存期間: 1年 / 2年 / 3年 / 5年 / 無期限
- 論理削除
- 一般 / アダルト自動分類
- 記事単位で「自動 / 一般 / アダルト」を上書き可能

### cronなし自動更新

- アクセス契機の `sa_maybe_refresh()`
- 更新ロックによる多重RSS取得防止
- RSS更新
- ランキングキャッシュ更新
- 1日1回程度の期限切れ処理

### 公開ページ

- トップ
- 日付別
- カテゴリ別
- 記事中継ページ
- 固定ページ
- サイト登録申請
- 削除依頼
- 一般RSS
- アダルトRSS
- カテゴリRSS
- XML Sitemap
- robots.txt
- 404
- 410

### アクセス解析

- IN
- Refererなしの Direct / Bookmark IN
- 自サイト内部移動のIN除外
- 除外URL
- 未登録IN
- 記事PV
- OUT
- 内部クリック
- IN 12時間重複抑制
- OUT 5分重複抑制
- PV 30秒重複抑制
- 不正疑いフラグ

### ランキング

- サイトIN 24時間 / 7日間
- 人気記事OUT 24時間 / 7日間
- 記事PV 24時間 / 7日間
- ランキングキャッシュ
- ランキング表示数 100 / 200 / 300
- 優遇サイト 最大3枠
- 優遇表示は実ランキング値を改変せず、おすすめ表示へ自然に反映

### サイト管理

- サイト追加・編集・削除扱い
- RSS URL
- カテゴリ
- 停止状態
- RSS取得状況・エラー
- 同一サイトとして扱う別名ホスト
- 優遇設定
- 未登録IN確認
- IN除外URL

### 登録申請

登録側:

```text
サイト登録申請
→ 管理人確認
→ 承認
→ RSS取得開始
→ 以後は登録サイト側で通常更新
```

管理人側:

```text
申請確認
→ 承認 / 却下
→ 承認時にサイト登録
→ RSS自動取得
```

登録者用会員マイページは設けていません。

### 削除依頼

- 記事単位
- 理由
- 連絡先
- 本文
- IP / User-Agent
- CSRF
- ハニーポット
- 連続送信制限
- 依頼受付だけでは自動削除しない
- 管理人が確認して処理

### 固定ページ

- 新規
- 編集
- 公開
- 下書き
- ゴミ箱
- Meta Description
- `/p/{slug}`

### 広告

- ヘッダー
- サイドバー
- 新着とおすすめの間
- おすすめと人気記事の間
- スマートフォン用位置キー
- 未設定・無効の広告は表示しない

### 管理画面

- ダッシュボード
- サイト・RSS
- 記事
- カテゴリ
- ランキング
- アクセス解析
- 申請・削除依頼
- 固定ページ
- 広告
- アダルト判定
- 除外URL
- 各種設定
- ログイン / ログアウト
- ログイン失敗時の一時制限

標準ログインURL:

```text
/admin/login0929.php
```

## セキュリティ

- PDO prepared statement
- 出力エスケープ
- CSRF
- セッション固定攻撃対策
- Secure / HttpOnly / SameSite Cookie
- ログイン試行制限
- SSRF対策
- 内部IP / localhost / link-local 等へのRSSアクセス抑止
- XML解析時の外部ネットワーク参照禁止
- DB登録済み元記事URLだけをOUT先に使用
- セキュリティヘッダー
- CSP
- X-Content-Type-Options
- Referrer-Policy
- Permissions-Policy
- 本番エラーを画面へ露出しない設計

## SEO / Google / Bing / W3C

Search Console、Bing Webmaster Toolsへ登録しなくても、通常のWebサイトと同様に対策します。

- title
- meta description
- canonical
- robots.txt
- XML Sitemap
- OGP
- Xカード基本タグ
- 構造化データ
- 404 / 410
- 正しいHTTPステータス
- URL正規化
- Google / Bing が通常クロールできる構造
- HTML5
- 正しい見出し・フォーム・label
- レスポンシブ
- 不要なJavaScriptを避ける
- Core Web Vitalsを悪化させる明白な構成を避ける

## 必要環境

- PHP 8.1 以上推奨
- PDO MySQL
- cURL
- DOM
- mbstring
- MySQL 5.7+ または MariaDB 10.4+ 推奨
- Apache mod_rewrite 推奨

## インストール

1. リポジトリのファイル一式をサーバーへ配置
2. Web公開ディレクトリを `public/` に設定
3. `/install.php` を開く
4. サイト情報、DB接続情報、管理者情報を入力
5. DBテーブル・INDEX・初期データを自動展開
6. 管理画面へログイン
7. カテゴリ、サイト、RSSを登録
8. 必要に応じて固定ページ・広告・アダルトワード・除外URLを設定

管理者パスワードは12文字以上を要求します。

## ディレクトリ

```text
.github/workflows/       PHP構文チェック
app/                     共通処理・RSS・解析・ランキング
database/schema.sql      DB定義
public/                  公開ディレクトリ
public/admin/            管理画面
public/assets/           CSS / JS
storage/cache/           キャッシュ
storage/logs/            ログ
storage/locks/           更新ロック
.env.example             環境設定例
README.md                仕様・導入・運用
```

## 初期設定値

- RSS更新: 60分
- 記事保存: 無期限
- ランキング表示: 100
- 記事成人判定: 自動
- 優遇サイト: なし
- タイムゾーン: Asia/Tokyo

## 品質確認

GitHub Actions の `PHP Lint` で、`app/` と `public/` 配下のPHP構文を自動チェックします。

本番公開前は、実サーバー上で以下も確認してください。

- DB接続
- DB自動展開
- RSS取得
- 500エラー
- 実RSS差異
- IN / PV / OUT
- 404 / 410
- robots.txt
- sitemap.xml
- canonical
- W3C上の重大問題
- スマートフォン表示
- 広告コード
- HTTPS
- セキュリティヘッダー
- Google / Bing の通常クロール可否
- 表示速度

## 開発方針

SmartAntennaは「RSSを並べるだけ」のシステムではありません。

RSS収集、記事中継、IN、PV、OUT、内部解析、ランキング、一般/アダルト分離、サイト管理、登録申請、削除管理を矛盾なく連動させることを前提とします。

不要な万能CMS化や大規模フレームワーク化は行わず、軽量・安全・高速・保守しやすい構成を維持します。
