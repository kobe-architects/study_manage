<?php

/*
 * 科目「国語」と大分類（現代文・古文・漢文）を作成し、漢文配下の中分類・小分類を
 * kanbun_structure.json から作成する（冪等：既存はそのまま利用）。
 * 実行: php database/data/create_kokugo_study_items.php
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

$st = json_decode(file_get_contents(__DIR__.'/kanbun_structure.json'), true);
$user = User::firstWhere('email', 'user@example.com') ?? User::orderBy('id')->first();

DB::transaction(function () use ($user, $st) {
    $sinfo = $st['subject'];
    $subject = Subject::firstOrCreate(
        ['user_id' => $user->id, 'name' => $sinfo['name']],
        [
            'code' => $sinfo['code'],
            'group_name' => $sinfo['group_name'],
            'color_soft' => $sinfo['color_soft'],
            'color_vivid' => $sinfo['color_vivid'],
            'sort_order' => (int) Subject::where('user_id', $user->id)->max('sort_order') + 1,
        ]
    );

    // 大分類（現代文・古文・漢文）
    $majorIds = [];
    foreach ($st['majors'] as $i => $mname) {
        $maj = MajorCategory::firstOrCreate(
            ['subject_id' => $subject->id, 'name' => $mname],
            ['sort_order' => $i]
        );
        $majorIds[$mname] = $maj->id;
    }

    // 漢文配下の中分類・小分類
    $kanbunId = $majorIds['漢文'];
    $midCount = 0;
    $itemCount = 0;
    foreach ($st['kanbunMids'] as $mi => $m) {
        $mid = MidCategory::firstOrCreate(
            ['major_category_id' => $kanbunId, 'name' => $m['mid']],
            ['sort_order' => $mi]
        );
        $midCount++;
        foreach ($m['subs'] as $si => $sub) {
            $item = StudyItem::firstOrCreate(
                ['mid_category_id' => $mid->id, 'name' => $sub],
                ['sort_order' => $si, 'included' => true]
            );
            if ($item->wasRecentlyCreated) {
                $itemCount++;
            }
        }
    }

    fwrite(STDOUT, "subject 国語 id={$subject->id} / majors=".count($majorIds)." / 漢文 mids={$midCount} / new items={$itemCount}\n");
});
