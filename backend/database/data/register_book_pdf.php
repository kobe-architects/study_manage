<?php

/*
 * 教材データ JSON の "pdf" 設定に従って、配置済みの PDF ファイルを教材に紐づける（quiz:register-pdf の日本語タイトル対応版）。
 * 実行: php database/data/register_book_pdf.php <book.json> <PDF の絶対パス> [--move]
 *   book.json は import_lecture_studysapuri.php と同じ形式（title/type で教材を特定）に加えて
 *   "pdf": {"title": "表示名", "map": "seq|none", "offset": 0, "links": "対応表.json（database/data 内・任意）"} を持つ。
 *   同じ教材に同じ表示名の PDF が既にあれば何もしない（再実行しても重複登録しない）。
 * 本番（csh）では日本語の引数が化けるため、タイトルは JSON 側に持たせてこのスクリプト経由で登録する。
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ResourceBook;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

$jsonName = $argv[1] ?? null;
$pdfPath = $argv[2] ?? null;
if (! $jsonName || ! $pdfPath) {
    fwrite(STDERR, "使い方: php database/data/register_book_pdf.php <book.json> <PDF の絶対パス> [--move]\n");
    exit(1);
}
$jsonPath = __DIR__.'/'.basename($jsonName);
if (! is_file($jsonPath)) {
    fwrite(STDERR, "データファイルが見つかりません: {$jsonPath}\n");
    exit(1);
}
$data = json_decode(file_get_contents($jsonPath), true);
$spec = $data['pdf'] ?? null;
if (! is_array($spec) || empty($spec['title'])) {
    fwrite(STDERR, "JSON に pdf 設定（title）がありません\n");
    exit(1);
}

$user = User::firstWhere('email', 'user@example.com') ?? User::orderBy('id')->first();
$book = ResourceBook::where('user_id', $user->id)
    ->where('type', $data['type'])
    ->where('title', $data['title'])
    ->first();
if (! $book) {
    fwrite(STDERR, "教材が見つかりません: [{$data['type']}] {$data['title']}（先に import_lecture_studysapuri.php で登録する）\n");
    exit(1);
}
$existing = $book->pdfs()->where('title', $spec['title'])->first();
if ($existing) {
    fwrite(STDOUT, "既に登録済み (pdf id={$existing->id}, {$existing->page_count}ページ)。何もしません。\n");
    exit(0);
}

$args = [
    'bookId' => (string) $book->id,
    'path' => $pdfPath,
    '--title' => $spec['title'],
    '--map' => $spec['map'] ?? 'seq',
    '--offset' => (string) ($spec['offset'] ?? 0),
    '--move' => in_array('--move', $argv, true),
];
if (! empty($spec['links'])) {
    $args['--links'] = __DIR__.'/'.basename($spec['links']);
}
$code = Artisan::call('quiz:register-pdf', $args);
fwrite(STDOUT, Artisan::output());
exit($code);
