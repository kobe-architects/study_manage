<?php

/*
 * 既存教材へ行を追記する（新規教材は作らない）。
 * 実行: php database/data/append_lecture_rows.php <data.json>
 *   data.json は import_lecture_studysapuri.php と同じ形式（title/type/subject/items[]）。
 *   title+type で既存の教材を特定し、その末尾に items を追記する。
 *   既に同じ seq_no の行がある場合はスキップ（再実行しても重複しない）。
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ResourceBook;
use App\Models\ResourceBookItem;
use App\Models\StudyItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$jsonName = $argv[1] ?? null;
if (! $jsonName) {
    fwrite(STDERR, "使い方: php database/data/append_lecture_rows.php <data.json>\n");
    exit(1);
}
$jsonPath = __DIR__.'/'.basename($jsonName);
if (! is_file($jsonPath)) {
    fwrite(STDERR, "データファイルが見つかりません: {$jsonPath}\n");
    exit(1);
}
$data = json_decode(file_get_contents($jsonPath), true);

$user = User::firstWhere('email', 'user@example.com') ?? User::orderBy('id')->first();

$book = ResourceBook::where('user_id', $user->id)
    ->where('type', $data['type'])
    ->where('title', $data['title'])
    ->first();
if (! $book) {
    fwrite(STDERR, "追記先の教材が見つかりません: [{$data['type']}] {$data['title']}\n");
    exit(1);
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

$existingSeq = $book->items()->pluck('seq_no')->filter()->all();
$existingSeq = array_flip($existingSeq);
$sort = (int) ($book->items()->max('sort_order') ?? -1) + 1;

DB::transaction(function () use ($user, $data, $book, $byPath, $existingSeq, &$sort) {
    $added = 0;
    $skipped = 0;
    $linked = 0;
    $missing = [];
    foreach ($data['items'] as $row) {
        if (! empty($row['seqNo']) && isset($existingSeq[$row['seqNo']])) {
            $skipped++;

            continue;
        }
        $key = $row['subject'].'|'.$row['major'].'|'.$row['mid'].'|'.$row['sub'];
        $itemId = $byPath[$key] ?? null;
        if ($itemId) {
            $linked++;
        } else {
            $missing[$key] = true;
        }
        ResourceBookItem::create([
            'resource_book_id' => $book->id,
            'study_item_id' => $itemId,
            'chapter' => $row['chapter'] ?? null ?: null,
            'seq_no' => $row['seqNo'] ?? null ?: null,
            'title' => $row['title'] ?? null ?: null,
            'meta' => ! empty($row['lecture']) ? ['講' => $row['lecture']] : null,
            'included' => true,
            'sort_order' => $sort++,
        ]);
        $added++;
    }

    fwrite(STDOUT, "追記完了: book id={$book->id} / 追加={$added} / スキップ(既存)={$skipped} / 紐づけ成功={$linked}\n");
    if ($missing) {
        fwrite(STDOUT, "未紐づけパス:\n  ".implode("\n  ", array_keys($missing))."\n");
    }
});
