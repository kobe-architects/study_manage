<?php

/*
 * 単発インポート: 「講義」スタディサプリ ベーシックレベル を個別学習一覧(教材)へ登録する。
 * データ元: database/data/<json>（*_目次_紐づけ_*.xlsx 由来）
 * 実行: php database/data/import_lecture_studysapuri.php [data.json]
 *   引数省略時は studysapuri_basic_math1.json を使用。
 *
 * 章=chapter / 番号=PART(seq_no) / タイトル=内容 / 講=meta["講"] / 小分類=study_item へパス紐づけ。
 * 同名・同種別の教材が既にある場合は何もしない（再実行で重複登録しない）。
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ResourceBook;
use App\Models\ResourceBookItem;
use App\Models\StudyItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$jsonName = $argv[1] ?? 'studysapuri_basic_math1.json';
$jsonPath = __DIR__.'/'.basename($jsonName);
if (! is_file($jsonPath)) {
    fwrite(STDERR, "データファイルが見つかりません: {$jsonPath}\n");
    exit(1);
}
$data = json_decode(file_get_contents($jsonPath), true);

$user = User::firstWhere('email', 'user@example.com') ?? User::orderBy('id')->first();
if (! $user) {
    fwrite(STDERR, "ユーザーが見つかりません\n");
    exit(1);
}

// 既存チェック（重複登録防止）
$existing = ResourceBook::where('user_id', $user->id)
    ->where('type', $data['type'])
    ->where('title', $data['title'])
    ->first();
if ($existing) {
    fwrite(STDOUT, "既に登録済み (book id={$existing->id})。何もしません。\n");
    exit(0);
}

// 小分類(StudyItem)を 科目|大|中|小 のパスで索引化
$byPath = [];
$items = StudyItem::with('mid.major.subject')
    ->whereHas('mid.major.subject', fn ($q) => $q->where('user_id', $user->id))
    ->get();
foreach ($items as $it) {
    $s = $it->mid->major->subject;
    $byPath[$s->name.'|'.$it->mid->major->name.'|'.$it->mid->name.'|'.$it->name] = $it->id;
}

$subjectId = optional($items->first(fn ($it) => $it->mid->major->subject->name === $data['subject']))->mid->major->subject->id
    ?? \App\Models\Subject::where('user_id', $user->id)->where('name', $data['subject'])->value('id');

DB::transaction(function () use ($user, $data, $byPath, $subjectId) {
    $book = ResourceBook::create([
        'user_id' => $user->id,
        'type' => $data['type'],
        'title' => $data['title'],
        'subject_id' => $subjectId,
        'sort_order' => (int) ResourceBook::where('user_id', $user->id)->where('type', $data['type'])->max('sort_order'),
    ]);

    $sort = 0;
    $linked = 0;
    $missing = [];
    foreach ($data['items'] as $row) {
        $key = $row['subject'].'|'.$row['major'].'|'.$row['mid'].'|'.$row['sub'];
        $itemId = $byPath[$key] ?? null;
        if ($itemId) {
            $linked++;
        } else {
            $missing[$key] = true;
        }
        // meta は行に meta オブジェクトがあればそれを、無ければ従来の 講(lecture) を採用
        $meta = null;
        if (! empty($row['meta']) && is_array($row['meta'])) {
            $meta = array_filter($row['meta'], fn ($v) => $v !== null && $v !== '');
            $meta = $meta ?: null;
        } elseif (! empty($row['lecture'])) {
            $meta = ['講' => $row['lecture']];
        }
        ResourceBookItem::create([
            'resource_book_id' => $book->id,
            'study_item_id' => $itemId,
            'chapter' => ($row['chapter'] ?? '') ?: null,
            'seq_no' => ($row['seqNo'] ?? '') ?: null,
            'check_flag' => ($row['checkFlag'] ?? '') ?: null,
            'title' => ($row['title'] ?? '') ?: null,
            'difficulty' => ($row['difficulty'] ?? '') ?: null,
            'meta' => $meta,
            'included' => true,
            'sort_order' => $sort++,
        ]);
    }

    fwrite(STDOUT, "登録完了: book id={$book->id} / 行数={$sort} / 紐づけ成功={$linked}\n");
    if ($missing) {
        fwrite(STDOUT, "未紐づけパス:\n  ".implode("\n  ", array_keys($missing))."\n");
    }
});
