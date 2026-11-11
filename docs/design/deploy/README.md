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
- **ホーム画面に追加（PWA）用のファイル**（`dist` 直下。中身が変わったときだけで可）:
  ```
  frontend/dist/manifest.webmanifest
  frontend/dist/apple-touch-icon.png
  frontend/dist/icon-192.png
  frontend/dist/icon-512.png
  frontend/dist/icon-maskable-512.png
  frontend/dist/favicon.svg
  →  backend/public/
  ```
  これらが無いと、存在しないパスとして index.html が返り、iPhone・iPad でホーム画面に追加したときに
  アイコン・全画面起動が効かない（2026-09-30 追加）

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

## 12. 小テスト機能（2026-09-10 追加）の運用メモ

- 依存パッケージが増えている（`setasign/fpdi` / `setasign/fpdf`）。更新時は必ず
  `php -d memory_limit=-1 ~/www/composer.phar install --no-dev --optimize-autoloader` を実行する。
- 教材 PDF・出題 PDF・回答写真・添削画像は `backend/storage/app/private/`（Git 管理外）に保存される。
  - `book-pdfs/{教材ID}/` … 紐づけた PDF 本体と、ページ単位に抽出したキャッシュ（`pages/{pdfID}/pN.pdf`）
  - `quizzes/{小テストID}/` … 出題 PDF（quiz.pdf）・回答写真（answers/）・添削画像（annotated/）・添削結果 PDF
- 大容量 PDF を画面からアップロードする場合は 4MB ずつの分割アップロードなので `post_max_size` の影響を受けない。
  SFTP で置いたファイルを登録する場合は artisan コマンドを使う:
  ```
  cd ~/www/study_manage/backend
  php artisan quiz:register-pdf {教材ID} /path/to/file.pdf --title="例題のみ（例題No.=ページ）" --map=seq --offset=0 --move
  ```
  `--map=seq` は「一覧データの番号 + オフセット = PDF ページ」の対応、`--map=none` は手動選択。
  `--links=対応表.json`（{出題ページ: 解答ページ}）を付けると採点・添削画面で解答ページを自動表示する
  （解答つき PDF が別にあればそのページ、問題と解説が 1 冊の教材なら同じ PDF 内のページ）。
- 本番のシェル（csh）では日本語の引数が化けるため、教材データ JSON に `pdf` 設定（表示名・map・offset・links）を持たせて
  `php database/data/register_book_pdf.php {教材JSON} /path/to/file.pdf --move` で登録する（2026-11-02 の英語長文ハイパートレーニングから）。
  行の登録は `php database/data/create_study_items_under_major.php {構造JSON}` → `php database/data/import_lecture_studysapuri.php {教材JSON}`。
- 教材 ID は `php artisan tinker --execute='print_r(\App\Models\ResourceBook::pluck("title","id")->all());'` で確認できる。

## 13. 英単語帳「LEAP basic」・英単語小テスト（2026-09-10 追加）の運用メモ

- 2026-09-18: マイグレーション `2026_09_18_000001_add_score_to_quizzes` で quizzes に `score`（得点）と `max_score`（満点）を追加し、
  採点済みの小テストはページ得点・ページ満点の合計を引き継ぐ。ページ別の○△×（quiz_pages.mark / score）は使わなくなった（列は残す）。
- 2026-09-14: マイグレーション `2026_09_14_000001_add_examples_and_reference_to_vocabularies` で vocabularies に
  `examples`（JSON: 複数例文）と `reference_note`（参考）を追加し、LEAP basic の memo（"No.N ..."）を reference_note へ移す。
  例文データを更新した場合は `php artisan vocab:import-leap` を再実行する（leap_basic.json は Git 管理）。

- マイグレーション `2026_09_11_000001_add_vocab_test_to_quiz_pages` で quiz_pages に英単語テスト用の列を追加し、
  既存の単語帳名「大学入試 必修英単語」を「鉄壁」に改名する（`php artisan migrate --force` で自動適用）。
- LEAP basic の単語データは Git 管理の `backend/database/data/leap_basic.json`。サーバーで登録・更新する場合:
  ```
  cd ~/www/study_manage/backend
  php artisan vocab:import-leap            # 最初の owner ユーザーに登録。--user=ID で指定可
  ```
  同名（LEAP basic）の単語帳があれば丸ごと入れ替える（その単語帳の学習履歴は消える）。
- 英単語テストの問題用紙・解答用紙はブラウザ側で JPEG 化して送るため、講師側の「出題する」は
  ページ数分の画像（1 枚 200〜400KB）を multipart で POST する。`post_max_size=8M` に収まるよう 1 小テストの
  英単語テストは数ページ程度に留める。保存先は `storage/app/private/quizzes/{ID}/vocab/`。


## 14. チェックシート・「あいまい」（2026-11-02 追加）の運用メモ

- マイグレーション `2026_11_02_000001_add_is_unsure_to_attempts` で `grammar_attempts` と `vocabulary_attempts` に
  `is_unsure`（正解だったが自信がない＝あいまい）を追加する（`php artisan migrate --force` で自動適用）。
- チェックシート（英文法・語法は付属 PDF と同じ並び、英単語はセクション → 単語）は画面側で HTML を組み立てて印刷する
  （`frontend/src/lib/checkSheet.ts`）。サーバーは日ごとの結果だけを返す（`/grammar/checksheet`・
  `/study-resources/{id}/vocabularies/checksheet`。`tz` は端末のタイムゾーンのずれ・分。既定 540＝日本時間）。
- 英文法・語法の章・Theme 名・形式・区分は `frontend/src/data/scrambleSheet.ts`（PDF から起こした固定データ）。

## 15. 間違いノート 本棚・問題解答モード（2026-11-05 追加）の運用メモ

- マイグレーション `2026_11_05_000001_add_decks_and_review_to_mistake_notes` で `mistake_decks`（デッキ＝本棚の本）・
  `mistake_reviews`（解答モードの評価履歴）を作り、`mistake_notes` にデッキと復習状態（間隔・係数・次回）の列を追加する
  （`php artisan migrate --force` で自動適用）。追加の Composer 依存はない。
- 科目「その他」は subjects に行を作らず `mistake_notes.subject_id = NULL`（API では subjectId = -1）。
- 解答の画像は `mistake_note_images.role = 'answer'`（保存先は従来と同じ `storage/app/private/mistake-notes/`）。

## 16. 英語学習・数学学習・学習カード・忘却曲線の復習（2026-11-09 追加）の運用メモ

- 画面構成: 「英単語クイズ」「英文法・語法」のメニューは「英語学習」（タブ: スクランブル／英単語／鉄緑会 英文法）に統合し、
  「数学学習」（タブ: 合格る確率／合格る計算）を追加した。旧 URL `/quiz` `/grammar` は英語学習の該当タブへリダイレクトする。
- マイグレーション（`php artisan migrate --force` で自動適用。追加の Composer 依存はない）:
  - `2026_11_09_000001_create_review_states_and_quiz_sessions` … `review_states`（忘却曲線の復習状態。生徒 × 問題。kind = grammar / vocab / card）、
    `quiz_sessions`（1 回の出題の記録: 問題数・正解・あいまい・解答時間の合計）、`grammar_attempts` / `vocabulary_attempts` に
    `quiz_session_id`・`elapsed_ms`（解答時間）・`review_prev`（回答前の復習状態。あいまい・正誤の付け替えでやり直す）。
  - `2026_11_09_000002_create_card_tables` … `card_decks` / `card_items` / `card_attempts`（学習カード）、`resource_books.student_only`（生徒側限定の教材）。
- 復習の規則は `App\Support\ReviewScheduler`（間違いノートと同じ: 不正解=もう一度 10 分後・正解だがあいまい=難しい・正解=正解。
  係数 1.3〜3.0、最長 365 日）。期限が来た問題は各画面の「今日の復習」に数えられ、ボタンで古い順に出題できる。
- 出題はすべて 4 択（`frontend/src/lib/cardChoices.ts` で組み立て。2026-11-10）:
  例文の和訳は正しい和訳を選ぶ（誤答＝同じ週の他の例文の和訳）、空所補充は空所ごとに語句を選ぶ（誤答＝他の例文の空所の語句）、
  囲みは 1 つが 1 枚のカードで、行ごとに意味→表現／表現→意味を選び全部正解で正解（誤答＝同じ週の他の囲みの行）、
  合格るは例題の画像を見て小問ごとに答えを選ぶ（答えと誤答は OCR からデータに入れてある `extra.subs`）。解答後に「詳細を表示」で解説の画像。
  4 択にできないカード（和訳の無い例文・表だけの囲み・答えを読み取れなかった例題）は従来どおり「答えを見る」→ ○／× の自己判定。
- 学習カードのデータ（Git 管理）: `backend/database/data/cards_tetsuryoku_grammar.json`（鉄緑会 英文法。囲み 457・例文 1358）、
  `cards_ukaru_kakuritsu.json`（合格る確率。例題 106 枚）、`cards_ukaru_keisan.json`（合格る計算。例題 87 枚）。登録・更新は
  ```
  php artisan cards:import --file=database/data/cards_tetsuryoku_grammar.json [--prune] [--reset]
  ```
  （束は key、カードは (束, no) で upsert。回答履歴・復習状態は残る。`--prune` は JSON に無いカードを削除。
  `--reset` はその束の回答履歴・復習状態・出題履歴を消してから登録する。no の振り直しなどカードの中身が変わったときに付ける）。
- カードの画像（Git 管理外。`storage/app/private/cards/{束の key}/`。合計約 60MB）は WinSCP で put する。
  鉄緑会は例文の切り抜き `pNNN_exNN.jpg` と囲みの切り抜き `pNNN_boxN.jpg`、合格るは 例題の切り抜き `eNNN[a-f].jpg` と解説（枠の下〜類題の手前）`sNNN[a-f].jpg`
  （1 つの ITEM に例題 A/B があるときは a, b …）。画像を差し替えたときは URL の `?v=`（画像名の md5）が変わるのでブラウザのキャッシュは気にしなくてよい。
- 対応する個別学習データ（教材）と PDF の登録は、日本語の引数を避けるため JSON 経由:
  ```
  php database/data/register_card_book.php book_tetsuryoku_grammar.json pages_tetsuryoku_grammar.json --pdf=/path/tk.pdf --move
  php database/data/register_card_book.php book_ukaru_kakuritsu.json pages_ukaru_kakuritsu.json --pdf=/path/kk.pdf --answer-pdf=/path/kka.pdf --move
  php database/data/register_card_book.php book_ukaru_keisan.json pages_ukaru_keisan.json --pdf=/path/kc.pdf --answer-pdf=/path/kca.pdf --move
  ```
  教材（無ければ作成。`studentOnly` を反映）・行（週／ITEM ごと）・PDF（`row_pages` = 行→ページ、問題 PDF の `page_links` = 類題ページ→解答ページ）を登録する。再実行可。
- 鉄緑会 英文法は生徒側限定（`resource_books.student_only` と `card_decks.student_only`）。講師の小テスト出題（`/tutor/quiz-books` など）には出ない。
- データの作り直し（スクリプトは `docs/design/deploy/ocr/` に置いてある。Windows + Python。pymupdf / winrt / opencv / numpy / Pillow）:
  5 冊の PDF（docs/other、Git 管理外）は画像のみなので `ocr_batch.py` で Windows OCR（winrt。ja と en-US）により全ページを JSON に文字起こしし、
  鉄緑会（`build_tk.py`）は週見出し・`N.N` 見出し・`Ex.` 行（en-US OCR）・囲み（OpenCV の矩形検出）を取り出し、太字（空所にする語）はストローク幅の比（中央値の 1.12 倍以上）で判定、
  囲みの各行は英語（en-US OCR）と意味（ja OCR）を位置の重なりで振り分けて `extra.rows` に入れる。
  合格る（`build_math2.py`）は例題の枠（桃色の細い罫の矩形。左上の「例題」ラベルを桃色の文字密度か OCR で確認）を色で検出して例題の画像にし、
  枠の下〜類題バッジ（桃色・色検出）までを解説の画像にする。答えは解説の「解答」見出し以降の OCR 行から「よって…は N 通り」「= N (通り)」「最大公約数は 6」、縦に並んだ分数などを拾い、
  問題文との整合（確率なら分数、何通りなら整数）で検証し、数値を変えた誤答 3 つを付ける。読めなかった例題は自己判定のまま（合格る確率は約 4 割、合格る計算は答えが式なのでほとんど）。
  `build_math.py` は旧版（類題＋解答編ページ）で、ITEM 見出し・類題バッジ・解答編の脚注の関数を `build_math2.py` が流用している。
- チェックシート: 学習カードは `GET /cards/decks/{id}/checksheet`、個別学習データは画面側で行の学習日（赤=0・青=2・それ以外=1）から組み立てる。
  英単語のチェックシートは A4 横 1 段で回数欄を 30 回ぶん並べる（`lib/checkSheet.ts` の `layout: 'landscape1'`）。
- 出題履歴: `GET /quiz-sessions?kind=&refId=|refKey=`。各出題は開始時に `POST /quiz-sessions`、終了時に `POST /quiz-sessions/{id}/finish`（回答から集計。回答 0 なら削除）。

## 17. 英文法ファーストステップ（高校リード問題集。2026-11-12 追加）の運用メモ

- 「英語学習」に「ファーストステップ」タブを追加。中身は英文法・語法（スクランブル）と同じ画面（`GrammarView` の `series="first_step"`）で、
  問題は `grammar_questions` の book = `first_step`（全問 format = image）。STEP 1・STEP 2・まとめのテスト 1〜5・総合問題 1・2 の
  小問 1 つが 1 問（1199 問。章 = 第1章〜第20章・まとめのテスト（chapter_no 21〜25）・総合問題（26・27）、theme = 教材のページ、
  importance = 区分「STEP 1 / STEP 2 / まとめのテスト / 総合問題」、source = 「p.5 STEP 1・1A〜C (1)」）。
  マイグレーションは無い（既存の列だけ）。
- 出題: 問題は教材の画像（大問の見出し + その小問 + 語群）。答えが ア〜オ の記号なら下部の ア〜エ ボタン（`choices`・`choiceCount`・`answerIndex`）、
  英単語 1〜3 語の空所なら画像の下の入力欄に記入して自動採点（`answers`。〔 〕[ ] は別解。採点が合わなければ「解説」で正解にする／不正解にする）、
  書きかえ・和訳・英作文などは「答えを見る」で答えの文字と解答の箱の画像を出して ○／× の自己判定。「解説」→「詳細解説」で解答編の解説の画像。
- チェックシート（`lib/firstStepSheet.ts`）は章 → 区分（ページ）→ 小問の並び。API は `GET /grammar/checksheet?book=first_step`。
- データ: `backend/database/data/first_step_grammar.json`（Git 管理）を `php artisan grammar:import --file=database/data/first_step_grammar.json`
  で登録（(book, no) で upsert。回答履歴は残る）。画像（Git 管理外。約 48MB・1820 枚）は `storage/app/private/grammar/first_step/`
  （問題 `qNNNN.jpg`、解答の箱 `aPPP_K.jpg`、解説 `ePPP_K.jpg`。PPP = ページ、K = ページ内の大問の順）に置き、WinSCP で put する。
- 作り直し: `docs/design/deploy/ocr/build_fs.py <scratch>`（<scratch>/ocr/fsq・fsa に ocr_batch.py と同じ要領で OCR した JSON を置く。250dpi・ja と en-US）。
  解答編の「P.5 STEP 1」の見出しで演習ページを知り、問題編は左端の大問ラベルと「(2)」以降の小問番号で切る（「(1)」は OCR で落ちやすいので見出し直後の行とみなす）。
  答えは「(1) で始まる行」と大問ラベルで箱を分け、小問数と突き合わせる。合わない大問（約 35／300）は答えの文字を付けず「箱の画像を見て自己判定」になる。
  確認用に `<scratch>/out/fs/REPORT.txt`（ページごとの突き合わせ結果）と `answers.txt`（全問の答え）を出す。
