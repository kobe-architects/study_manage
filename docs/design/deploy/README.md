# さくらインターネット レンタルサーバー デプロイ手順

受験ナビ（Laravel 13 + Vue 3 SPA + MySQL 8）を **さくらのレンタルサーバ スタンダード** に
デプロイするための手順書。初回デプロイで実際に踏んだ落とし穴と対処を反映している。

---

## 0. 構成の前提（この環境の確定値）

| 項目 | 値 |
|---|---|
| プラン | さくらのレンタルサーバ **スタンダード**（SSH・MySQL・PHP 8.3 が必要） |
| アカウント | `navyllama5` |
| サーバーOS | FreeBSD（**ログインシェルは csh 系**。`%` プロンプト） |
| PHP (CLI) | 8.3.31 |
| MySQL | 8.0.44（**SSL非対応**。後述の認証問題に直結） |
| Composer | `~/www/composer.phar`（サーバーに設置済み） |
| Git / リポジトリ | `https://github.com/kobe-architects/study_manage`（private, PAT認証） |
| アプリ配置先 | `~/www/study_manage/`（`backend/` `frontend/` `docs/`） |
| ドメイン | `study-manage.2-d.jp`（無料SSL / Let's Encrypt） |
| DB名 | `navyllama5_study_manage` |
| DBユーザー | **`navyllama5_study_manage`**（＝DB名と同一。`navyllama5` ではない） |
| DBパスワード | コントロールパネルで設定した値 |
| DBホスト | `mysql3113.db.sakura.ne.jp`（別名 `mysql80.navyllama5.sakura.ne.jp`） |

### 役割分担
- **Git（GitHub）** … ソースコードのバージョン管理・配送（サーバーで `git pull`）
- **ローカルビルド + FTP** … フロント（Vue）の成果物 `dist`（サーバーに Node が無いため）
- **mysqldump + import** … 初期データ（seeder では実データが再現されないため）

---

## 1. ローカルでの準備

### 1-1. フロントエンドをビルド
```powershell
cd frontend
npm ci
npm run build        # frontend/dist/ が生成される
```

> ⚠️ **`npm ci` の前に Vite 開発サーバー（`npm run dev`）を必ず停止**すること。
> 起動中だと `node_modules/@rolldown/...node` がロックされ、`npm ci` が
> `EPERM: operation not permitted, unlink` で失敗し node_modules が壊れる。
> ```powershell
> Get-Process node | Where-Object { $_.CommandLine -like '*vite*' } | Stop-Process -Force
> ```

### 1-2. 本番用 .env を用意
`backend/.env.production` を作成（Git 管理外）。要点:
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # php artisan key:generate --show で生成した値
APP_URL=https://study-manage.2-d.jp

DB_CONNECTION=mysql
DB_HOST=mysql80.navyllama5.sakura.ne.jp
DB_PORT=3306
DB_DATABASE=navyllama5_study_manage
DB_USERNAME=navyllama5_study_manage    # ← DB名と同じ。ここ重要
DB_PASSWORD=（コントロールパネルの値）

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync                   # 共有サーバーでは Redis/DB キューを使わない
SANCTUM_STATEFUL_DOMAINS=study-manage.2-d.jp
SESSION_DOMAIN=study-manage.2-d.jp
```
> `MYSQL_ATTR_SSL_CA` は**設定しない**（さくらの MySQL は SSL 非対応のため）。

### 1-3. ローカル DB をダンプ（初期データ移送用）
```powershell
& "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqldump.exe" `
  --user=root --password=password --host=127.0.0.1 --port=3306 `
  --single-transaction --no-tablespaces --set-gtid-purged=OFF `
  --default-character-set=utf8mb4 --add-drop-table `
  study_manage --result-file=study_manage_dump.sql
```
- `--set-gtid-purged=OFF` / `--no-tablespaces` … 共有サーバーで import 時に権限エラーを避ける
- `--add-drop-table` … 再 import 時に既存テーブルを置換
- ダンプは `*.sql`（`.gitignore` 済み）。**機密データを含むため Git に入れない**

---

## 2. さくら コントロールパネルでの準備

1. **データベース作成**（データベース → 新規追加、文字コード `utf8mb4`）
   - 表示される **ホスト名 / DB名 / ユーザー名 / パスワード**を控える
   - ⚠️ ユーザー名は **DB名と同一**（`navyllama5_study_manage`）
2. **PHP 8.3** を選択
3. **SSH を有効化**
4. **ドメイン追加** `study-manage.2-d.jp`
   - **Web公開フォルダ**（後述）… 相対パスで指定
   - **無料SSL（Let's Encrypt）を有効化**

---

## 3. サーバーへのコード配置（初回）

### 3-1. clone（ホーム相当の www 直下で実行）
```bash
cd ~/www
git clone https://＜PAT＞@github.com/kobe-architects/study_manage.git
```
> ⚠️ **事前に `study_manage` ディレクトリを作らないこと**。`git clone` が自分で
> リポジトリ名のフォルダを作るため、作成済みの同名フォルダ内で clone すると
> `study_manage/study_manage/` と二重になる。二重になったら:
> ```bash
> cd ~/www
> mv study_manage/study_manage study_manage_repo && rm -rf study_manage && mv study_manage_repo study_manage
> ```

### 3-2. Composer で依存インストール
```bash
cd ~/www/study_manage/backend
php -d memory_limit=-1 ~/www/composer.phar install --no-dev --optimize-autoloader
```
> ⚠️ `vendor/` は Git 管理外。未インストールだと `php artisan` が**無反応**になる
> （さくらの CLI は `display_errors=Off` のため致命的エラーが画面に出ない）。
> 確認: `php -d display_errors=1 artisan --version`

### 3-3. .env を配置
`backend/.env.production` の内容を FTP で `backend/.env` として置く（またはサーバーで `vi .env`）。

---

## 4. データベース（初期データ移送）

### 4-1. ダンプを FTP でアップ
`study_manage_dump.sql` を `~/www/`（等）にアップ。

### 4-2. import
```bash
cd ~/www
mysql -h mysql3113.db.sakura.ne.jp -u navyllama5_study_manage -p＜パスワード＞ \
  --get-server-public-key navyllama5_study_manage < study_manage_dump.sql
```

### 4-3. 接続確認
```bash
cd ~/www/study_manage/backend
php artisan config:clear
php artisan migrate:status          # 全マイグレーションが [n] Ran ならOK
```
> **`migrate --seed` は実行しない**。seeder は最小デモ（英単語0件・手動インポート分なし）
> しか作らず、ダンプで入れた実データと重複・不整合を起こす。

---

## 5. MySQL 8 認証の注意（重要な落とし穴）

MySQL 8 の既定認証 `caching_sha2_password` は**暗号化接続を要求**するが、
**さくらの MySQL は SSL 非対応**。このため素の接続は失敗する:

| エラー | 意味 |
|---|---|
| `[2006] MySQL server has gone away`（PDO/Laravel） | 平文で caching_sha2 に弾かれた |
| `ERROR 2061 ... Authentication requires secure connection`（mysql CLI） | 同上 |
| `ERROR 2026 ... SSL is required but the server doesn't support it` | サーバーが SSL 自体を持たない |

### 対処
- **ユーザー名を正す**（`navyllama5` ではなく `navyllama5_study_manage`）。
  誤ると `ERROR 1045 Access denied`。
- CLI は `--get-server-public-key` を付けると平文＋RSA公開鍵で認証できる。
- **一度この方式で認証に成功すると、MySQL がハッシュをキャッシュ**し、以降は
  Laravel からの平文接続も通る（`migrate:status` が通るのはこのため）。

### 恒久対策（任意・推奨）
MySQL 再起動でキャッシュが消えると再発しうる。安定させるなら認証方式を
`mysql_native_password` に変更する（対話モードで。csh のクォート問題を避ける）:
```bash
mysql -h mysql3113.db.sakura.ne.jp -u navyllama5_study_manage -p＜パスワード＞ --get-server-public-key navyllama5_study_manage
```
```sql
ALTER USER USER() IDENTIFIED WITH mysql_native_password BY '＜パスワード＞';
```
> `config/database.php` には「`MYSQL_ATTR_SSL_CA` が設定されている時だけ SSL 有効化」
> のガードを入れてあるが、さくらは SSL 非対応なので**このenvは設定しない**（ガードは無害）。

---

## 6. フロントエンド（SPA）の配信

### 6-1. SPA フォールバックルート
Vue Router は履歴モード（`createWebHistory`）。`/quiz` 等の直アクセスで 404 に
ならないよう `backend/routes/web.php` で SPA を返す（コミット済み）:
```php
Route::get('/{any?}', function () {
    return response()->file(public_path('index.html'));
})->where('any', '^(?!api).*$');   // /api 以外は index.html
```

### 6-2. dist を公開フォルダへ配置（FTP）
サーバーに Node が無いので、ローカルでビルドした成果物を FTP で置く:
```
frontend/dist/index.html  →  backend/public/index.html
frontend/dist/assets/     →  backend/public/assets/
```
- `public/index.php`（Laravel フロントコントローラ）はそのまま
- 静的アセット（`/assets/*`）は実体があるため Apache が直接配信
- 更新時は `public/assets/` を一度空にしてから入れると古いファイルが残らない

---

## 7. 公開フォルダ設定（重要な落とし穴）

さくらの「Web公開フォルダ」欄は **`~/www/` からの相対パス**。フルパスを入れると
`~/www/` + `/home/navyllama5/www/...` と**二重に連結**され、存在しないパスになり
`Not Found` になる。

- ❌ 誤: `/home/navyllama5/www/study_manage/backend/public`
- ✅ 正: **`study_manage/backend/public`**（→ `~/www/study_manage/backend/public` に解決）

---

## 8. 仕上げ（本番キャッシュ）

```bash
cd ~/www/study_manage/backend
php artisan config:cache
php artisan route:cache
php artisan storage:link      # 画像配信用（初回のみ）
```
> `.env` を変更したら必ず `php artisan config:clear` → `config:cache` し直す。

---

## 9. 動作確認

`https://study-manage.2-d.jp`:
1. ログイン画面が表示される
2. ログイン（`user@example.com` / `password`）→ 実データが表示される
3. `/quiz` を直接 URL 入力して 404 にならない（SPA フォールバック確認）

不具合時:
- **Not Found** → 公開フォルダのパス（相対指定・二重連結）を確認
- **500 / 白画面** → `storage/logs/laravel.log` を確認。`storage/` `bootstrap/cache/` の書き込み権限（705/755）
- **DB接続エラー** → §5（ユーザー名・caching_sha2）を確認

---

## 10. 後片付け（セキュリティ）

```bash
rm ~/www/study_manage_dump.sql          # サーバーのダンプ削除
```
ローカルの `study_manage_dump.sql` も削除（`.gitignore` 済みで Git には入らない）。

---

## 11. 2回目以降の更新フロー

```
# ローカル
（コード修正）→ git push
cd frontend && npm run build            # dist 更新時

# サーバー（SSH）
cd ~/www/study_manage/backend
git pull origin main
php -d memory_limit=-1 ~/www/composer.phar install --no-dev --optimize-autoloader   # 依存変更時
php artisan migrate --force             # 新規マイグレーション時のみ
php artisan config:cache && php artisan route:cache

# フロント更新時は dist を FTP で backend/public/ に上書き（assets は入替）
```

> サーバー上ではコードを直接編集しない（`.env` 除く）。編集はローカル→push→
> サーバーで pull を徹底すると `git pull` の衝突が起きない。
