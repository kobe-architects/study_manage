<?php

/*
 * 学習カードに対応する教材（個別学習データ）を登録し、PDF を紐づける。
 *   php database/data/register_card_book.php <book.json> <pages.json> [--pdf=問題PDFの絶対パス] [--answer-pdf=解答PDFの絶対パス] [--move]
 *
 * book.json: import_lecture_studysapuri.php と同じ形式（title/type/subject/items）＋ "studentOnly"（生徒側限定）＋ "pdf": {"title","map","offset"}。
 * pages.json: {"rowPages": {"行の添字(1始まり)": [PDFページ...]}} または
 *             {"itemPages": {"ITEM番号": [ページ...]}, "problemPage": {"ITEM番号": ページ or [ページ]}, "answerPages": {"ITEM番号": [解答PDFのページ...]}}
 * - 教材が無ければ作る（同じ種別・タイトルがあれば再利用）。行は無ければ登録する。student_only を JSON に合わせる。
 * - --pdf を渡すと quiz:register-pdf で登録し（同じ表示名が登録済みなら再利用）、row_pages（行 → ページ）を入れる。
 * - --answer-pdf も渡すと「解答」PDF として登録し、問題 PDF の page_links（問題ページ → 解答ページ）を入れる。
 * 本番（csh）では日本語の引数が化けるため、タイトルは JSON 側に持たせてこのスクリプト経由で登録する。再実行可。
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ResourceBook;
use App\Models\ResourceBookItem;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$args = array_slice($argv, 1);
$opts = ['pdf' => null, 'answer-pdf' => null, 'move' => false];
$pos = [];
foreach ($args as $a) {
    if (preg_match('/^--(pdf|answer-pdf)=(.+)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif ($a === '--move') {
        $opts['move'] = true;
    } else {
        $pos[] = $a;
    }
}
if (count($pos) < 2) {
    fwrite(STDERR, "使い方: php database/data/register_card_book.php <book.json> <pages.json> [--pdf=...] [--answer-pdf=...] [--move]\n");
    exit(1);
}
$bookJson = __DIR__.'/'.basename($pos[0]);
$pagesJson = __DIR__.'/'.basename($pos[1]);
foreach ([$bookJson, $pagesJson] as $f) {
    if (! is_file($f)) {
        fwrite(STDERR, "ファイルが見つかりません: {$f}\n");
        exit(1);
    }
}
$data = json_decode(file_get_contents($bookJson), true);
$pages = json_decode(file_get_contents($pagesJson), true);

$user = User::firstWhere('email', 'user@example.com') ?? User::where('role', 'owner')->orderBy('id')->first() ?? User::orderBy('id')->first();
if (! $user) {
    fwrite(STDERR, "ユーザーが見つかりません\n");
    exit(1);
}
$subjectId = \App\Models\Subject::where('user_id', $user->id)->where('name', $data['subject'] ?? '')->value('id');

// ---- 教材と行 ----
$book = ResourceBook::where('user_id', $user->id)->where('type', $data['type'])->where('title', $data['title'])->first();
if ($book) {
    fwrite(STDOUT, "教材は登録済み (book id={$book->id})\n");
} else {
    $book = ResourceBook::create([
        'user_id' => $user->id,
        'type' => $data['type'],
        'title' => $data['title'],
        'subject_id' => $subjectId,
        'sort_order' => (int) ResourceBook::where('user_id', $user->id)->where('type', $data['type'])->max('sort_order') + 1,
    ]);
    fwrite(STDOUT, "教材を作成 (book id={$book->id})\n");
}
$book->update(['student_only' => (bool) ($data['studentOnly'] ?? false)]);

$rowIds = []; // 行の添字(1始まり) → 行 ID
if ($book->items()->count() === 0) {
    DB::transaction(function () use ($book, $data, &$rowIds) {
        $sort = 0;
        foreach ($data['items'] as $i => $row) {
            $meta = ! empty($row['meta']) && is_array($row['meta']) ? array_filter($row['meta'], fn ($v) => $v !== null && $v !== '') : null;
            $item = ResourceBookItem::create([
                'resource_book_id' => $book->id,
                'study_item_id' => null,
                'chapter' => ($row['chapter'] ?? '') ?: null,
                'seq_no' => isset($row['seqNo']) && $row['seqNo'] !== '' ? (string) $row['seqNo'] : null,
                'title' => ($row['title'] ?? '') ?: null,
                'difficulty' => ($row['difficulty'] ?? '') ?: null,
                'meta' => $meta ?: null,
                'included' => true,
                'sort_order' => $sort++,
            ]);
            $rowIds[$i + 1] = $item->id;
        }
        fwrite(STDOUT, "行を登録: {$sort} 行\n");
    });
} else {
    foreach ($book->items()->orderBy('sort_order')->orderBy('id')->get() as $i => $item) {
        $rowIds[$i + 1] = $item->id;
    }
    fwrite(STDOUT, '行は登録済み: '.count($rowIds)." 行\n");
}
// ITEM 番号（seq_no）→ 行 ID
$rowBySeq = [];
foreach ($book->items()->get() as $item) {
    if ($item->seq_no !== null) {
        $rowBySeq[(string) $item->seq_no] = $item->id;
    }
}

// ---- PDF ----
$spec = $data['pdf'] ?? ['title' => '全ページ', 'map' => 'none', 'offset' => 0];
$registerPdf = function (string $path, string $title, string $map, int $offset) use ($book, $opts) {
    $existing = $book->pdfs()->where('title', $title)->first();
    if ($existing) {
        fwrite(STDOUT, "PDF「{$title}」は登録済み (pdf id={$existing->id}, {$existing->page_count}p)\n");

        return $existing;
    }
    if (! is_file($path)) {
        fwrite(STDERR, "PDF が見つかりません: {$path}\n");
        exit(1);
    }
    $code = Artisan::call('quiz:register-pdf', [
        'bookId' => (string) $book->id, 'path' => $path, '--title' => $title, '--map' => $map, '--offset' => (string) $offset, '--move' => $opts['move'],
    ]);
    fwrite(STDOUT, Artisan::output());
    if ($code !== 0) {
        exit($code);
    }

    return $book->pdfs()->where('title', $title)->orderByDesc('id')->first();
};

if ($opts['pdf']) {
    $pdf = $registerPdf($opts['pdf'], $spec['title'] ?? '全ページ', $spec['map'] ?? 'none', (int) ($spec['offset'] ?? 0));
    // row_pages
    $rowPages = [];
    if (! empty($pages['rowPages'])) {
        foreach ($pages['rowPages'] as $idx => $list) {
            if (isset($rowIds[(int) $idx])) {
                $rowPages[(string) $rowIds[(int) $idx]] = array_values(array_map('intval', (array) $list));
            }
        }
    } elseif (! empty($pages['itemPages'])) {
        foreach ($pages['itemPages'] as $no => $list) {
            if (isset($rowBySeq[(string) $no])) {
                $rowPages[(string) $rowBySeq[(string) $no]] = array_values(array_map('intval', (array) $list));
            }
        }
    }
    if ($rowPages) {
        $pdf->update(['row_pages' => $rowPages]);
        fwrite(STDOUT, '行→ページの対応を登録: '.count($rowPages)." 行\n");
    }
    // 解答 PDF と page_links
    if ($opts['answer-pdf']) {
        $ans = $registerPdf($opts['answer-pdf'], ($spec['answerTitle'] ?? '解答'), 'none', 0);
        $links = [];
        if (! empty($pages['problemPage']) && ! empty($pages['answerPages'])) {
            foreach ($pages['problemPage'] as $no => $pp) {
                $aps = $pages['answerPages'][(string) $no] ?? null;
                if (! $aps) {
                    continue;
                }
                $first = (int) ((array) $aps)[0];
                foreach ((array) $pp as $p) {
                    $links[(string) (int) $p] = $first;
                }
            }
        }
        if ($links) {
            $pdf->update(['page_links' => $links]);
            fwrite(STDOUT, '問題ページ→解答ページの対応を登録: '.count($links)." ページ\n");
        }
        // 解答 PDF 側にも行→ページ
        if (! empty($pages['answerPages'])) {
            $ansRows = [];
            foreach ($pages['answerPages'] as $no => $list) {
                if (isset($rowBySeq[(string) $no])) {
                    $ansRows[(string) $rowBySeq[(string) $no]] = array_values(array_map('intval', (array) $list));
                }
            }
            if ($ansRows) {
                $ans->update(['row_pages' => $ansRows]);
            }
        }
    }
}
fwrite(STDOUT, "完了\n");
