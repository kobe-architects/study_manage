<?php

/*
 * 既存の科目・大分類の配下に、中分類・小分類（学習項目）を作成する（冪等）。
 * 実行: php database/data/create_study_items_under_major.php <structure.json>
 *   structure.json: {"subjectName": "...", "majorName": "...", "mids": [{"mid": "...", "subs": ["...", ...]}, ...]}
 *   科目・大分類が無い場合は作成する（大分類は既存 major の末尾に追加）。
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MajorCategory;
use App\Models\MidCategory;
use App\Models\StudyItem;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$jsonName = $argv[1] ?? null;
if (! $jsonName) {
    fwrite(STDERR, "使い方: php database/data/create_study_items_under_major.php <structure.json>\n");
    exit(1);
}
$st = json_decode(file_get_contents(__DIR__.'/'.basename($jsonName)), true);
$user = User::firstWhere('email', 'user@example.com') ?? User::orderBy('id')->first();

$subject = Subject::firstWhere(['user_id' => $user->id, 'name' => $st['subjectName']]);
if (! $subject) {
    fwrite(STDERR, "科目が見つかりません: {$st['subjectName']}\n");
    exit(1);
}
$major = MajorCategory::firstOrCreate(
    ['subject_id' => $subject->id, 'name' => $st['majorName']],
    ['sort_order' => (int) MajorCategory::where('subject_id', $subject->id)->max('sort_order') + 1]
);

DB::transaction(function () use ($major, $st) {
    $newMids = 0;
    $newItems = 0;
    $baseMidSort = (int) MidCategory::where('major_category_id', $major->id)->max('sort_order');
    foreach ($st['mids'] as $mi => $m) {
        $mid = MidCategory::firstOrCreate(
            ['major_category_id' => $major->id, 'name' => $m['mid']],
            ['sort_order' => $baseMidSort + 1 + $mi]
        );
        if ($mid->wasRecentlyCreated) {
            $newMids++;
        }
        foreach ($m['subs'] as $si => $sub) {
            $item = StudyItem::firstOrCreate(
                ['mid_category_id' => $mid->id, 'name' => $sub],
                ['sort_order' => $si, 'included' => true]
            );
            if ($item->wasRecentlyCreated) {
                $newItems++;
            }
        }
    }
    fwrite(STDOUT, "major={$major->name} id={$major->id} / new mids={$newMids} / new items={$newItems}\n");
});
