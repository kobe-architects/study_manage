<?php

namespace App\Http\Controllers;

use App\Models\ResourceBookItem;
use App\Models\StudyItem;
use App\Models\StudyRecord;
use App\Support\XlsxHelper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecordController extends Controller
{
    private const EXPORT_HEADER = ['学習日', '種別', '科目', '大分類', '中分類', '小分類', '教材', '番号', 'タイトル', '色', '復習期限', '復習完了日'];

    private const COLOR_LABEL = ['red' => '赤', 'blue' => '青', 'green' => '緑'];

    /**
     * 学習記録の登録。
     * - 教材の行ベース: resourceBookItemId を指定（type/study_item_id は行から導出）
     * - レガシー: studyItemId + type を直接指定
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        if ($request->filled('resourceBookItemId')) {
            $data = $request->validate([
                'resourceBookItemId' => ['required', 'integer'],
                'studiedOn' => ['required', 'date'],
            ]);

            $row = ResourceBookItem::with('book')
                ->whereHas('book', fn ($q) => $q->where('user_id', $userId))
                ->findOrFail($data['resourceBookItemId']);
            abort_if($row->study_item_id === null, 422, 'この行は学習項目に紐づいていません。');

            $record = StudyRecord::create([
                'user_id' => $userId,
                'study_item_id' => $row->study_item_id,
                'resource_book_item_id' => $row->id,
                'type' => $row->book->type,
                'studied_on' => $data['studiedOn'],
            ]);

            return response()->json(['data' => ['id' => $record->id]], 201);
        }

        $data = $request->validate([
            'studyItemId' => ['required', 'integer'],
            'type' => ['required', 'in:講義,問題集,教科書'],
            'studiedOn' => ['required', 'date'],
        ]);

        StudyItem::whereHas('mid.major.subject', fn ($q) => $q->where('user_id', $userId))
            ->findOrFail($data['studyItemId']);

        $record = StudyRecord::create([
            'user_id' => $userId,
            'study_item_id' => $data['studyItemId'],
            'type' => $data['type'],
            'studied_on' => $data['studiedOn'],
        ]);

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    /**
     * 記録系の統計: 今週件数 / 連続学習 / 累計 / ヒートマップ / 最近の記録。
     */
    public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $today = Carbon::today();

        $records = StudyRecord::query()
            ->with('item.mid.major.subject')
            ->where('user_id', $userId)
            ->orderByDesc('studied_on')
            ->orderByDesc('id')
            ->get();

        // 今週（過去7日）
        $weekAgo = $today->copy()->subDays(7);
        $weekCount = $records->filter(fn ($r) => $r->studied_on->gte($weekAgo))->count();

        // ヒートマップ用 日別件数
        $countByDay = [];
        foreach ($records as $r) {
            $k = $r->studied_on->toDateString();
            $countByDay[$k] = ($countByDay[$k] ?? 0) + 1;
        }

        // 連続学習（今日/昨日から遡ってカウント）
        $streak = 0;
        $d = $today->copy();
        $guard = 0;
        while (! isset($countByDay[$d->toDateString()]) && $guard < 3) {
            $d->subDay();
            $guard++;
        }
        while (isset($countByDay[$d->toDateString()])) {
            $streak++;
            $d->subDay();
        }

        // 最近の記録 12件
        $recent = $records->take(12)->map(function ($r) {
            $subject = $r->item->mid->major->subject;

            return [
                'id' => $r->id,
                'date' => $r->studied_on->toDateString(),
                'subjectName' => $subject->name,
                'colorSoft' => $subject->color_soft,
                'colorVivid' => $subject->color_vivid,
                'major' => $r->item->mid->major->name,
                'mid' => $r->item->mid->name,
                'sub' => $r->item->name,
                'type' => $r->type,
            ];
        })->values();

        return response()->json([
            'data' => [
                'week' => $weekCount,
                'streak' => $streak,
                'total' => $records->count(),
                'heatmap' => $countByDay,
                'recent' => $recent,
            ],
        ]);
    }

    /**
     * 学習記録の一覧（期間指定）。学習記録の出力機能の画面表示用。
     * from / to は YYYY-MM-DD（どちらも省略可、省略時は全期間）。
     */
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->validatePeriod($request);
        $records = $this->recordsBetween($request->user()->id, $from, $to);

        $data = $records->map(function (StudyRecord $r) {
            $item = $r->item;
            $subject = $item?->mid?->major?->subject;
            $row = $r->bookItem;

            return [
                'id' => $r->id,
                'date' => $r->studied_on->toDateString(),
                'type' => $r->type,
                'subjectName' => $subject?->name,
                'colorSoft' => $subject?->color_soft ?? '#475569',
                'colorVivid' => $subject?->color_vivid ?? '#475569',
                'major' => $item?->mid?->major?->name,
                'mid' => $item?->mid?->name,
                'sub' => $item?->name,
                'bookTitle' => $row?->book?->title,
                'seqNo' => $row?->seq_no,
                'rowTitle' => $row?->title,
                'color' => $r->color,
                'reviewOn' => $r->review_on?->toDateString(),
                'reviewedOn' => $r->reviewed_at?->toDateString(),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * 学習記録の Excel 出力（期間指定）。列構成は index() と同じ情報を xlsx で返す。
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->validatePeriod($request);
        $records = $this->recordsBetween($request->user()->id, $from, $to);

        $rows = $records->map(function (StudyRecord $r) {
            $item = $r->item;
            $row = $r->bookItem;

            return [
                $r->studied_on->toDateString(),
                $r->type,
                $item?->mid?->major?->subject?->name,
                $item?->mid?->major?->name,
                $item?->mid?->name,
                $item?->name,
                $row?->book?->title,
                $row?->seq_no,
                $row?->title,
                $r->color !== null ? (self::COLOR_LABEL[$r->color] ?? $r->color) : null,
                $r->review_on?->toDateString(),
                $r->reviewed_at?->toDateString(),
            ];
        })->all();

        $binary = XlsxHelper::write(self::EXPORT_HEADER, $rows);
        $filename = sprintf('study_records_%s_%s.xlsx', $from ?? 'all', $to ?? Carbon::today()->toDateString());

        return response()->streamDownload(function () use ($binary) {
            echo $binary;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function validatePeriod(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [$data['from'] ?? null, $data['to'] ?? null];
    }

    private function recordsBetween(int $userId, ?string $from, ?string $to)
    {
        return StudyRecord::query()
            ->with(['item.mid.major.subject', 'bookItem.book'])
            ->where('user_id', $userId)
            ->when($from, fn ($q) => $q->whereDate('studied_on', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('studied_on', '<=', $to))
            ->orderByDesc('studied_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 復習項目一覧: 復習期限（review_on）を持つ学習記録を1件=1復習タスクとして返す。
     * reviewed_at が null のものは未復習、値があるものは復習済み。
     * 未復習を期限の昇順で先頭に、復習済みを完了日の降順で後ろに並べる。
     */
    public function reviews(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $today = Carbon::today();

        $records = StudyRecord::query()
            ->whereNotNull('resource_book_item_id')
            ->whereNotNull('review_on')
            ->whereHas('bookItem.book', fn ($q) => $q->where('user_id', $userId))
            ->with(['bookItem.book:id,type,title,subject_id', 'bookItem.book.subject', 'item.mid.major'])
            ->get();

        $data = $records->map(function (StudyRecord $r) use ($today) {
            $row = $r->bookItem;
            $book = $row?->book;
            $subject = $book?->subject;
            $item = $r->item;
            $reviewed = $r->reviewed_at !== null;

            return [
                'id' => $r->id,          // 学習記録ID（復習タスクの単位）
                'rowId' => $row?->id,
                'title' => $row?->title,
                'sub' => $item?->name,
                'bookTitle' => $book?->title,
                'type' => $book?->type,
                'subjectName' => $subject?->name,
                'colorSoft' => $subject?->color_soft ?? '#475569',
                'colorVivid' => $subject?->color_vivid ?? '#475569',
                'major' => $item?->mid?->major?->name,
                'mid' => $item?->mid?->name,
                'studiedOn' => $r->studied_on->toDateString(),
                'reviewOn' => $r->review_on->toDateString(),
                'color' => $r->color,
                'reviewed' => $reviewed,
                'reviewedOn' => $r->reviewed_at?->toDateString(),
                'overdue' => ! $reviewed && $r->review_on->lt($today),
            ];
        });

        $pending = $data->where('reviewed', false)->sortBy('reviewOn')->values();
        $done = $data->where('reviewed', true)->sortByDesc('reviewedOn')->values();

        return response()->json(['data' => $pending->concat($done)->values()]);
    }

    /**
     * 復習の完了記録。対象の学習記録を「復習済み」にし、復習セッションを
     * 新しい学習記録として登録（次の復習期限も予約）する。
     */
    public function completeReview(Request $request, StudyRecord $record): JsonResponse
    {
        abort_unless($record->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'studiedOn' => ['required', 'date'],
            'color' => ['nullable', 'in:red,blue,green'],
            'reviewOn' => ['nullable', 'date'],
        ]);

        $row = $record->bookItem;
        abort_if($row === null || $row->study_item_id === null, 422, 'この記録は学習項目に紐づいていません。');

        // 元の復習タスクを完了扱い
        $record->update(['reviewed_at' => $data['studiedOn']]);

        // 復習セッションを新しい学習記録として登録（次回の復習期限を予約）
        $new = StudyRecord::create([
            'user_id' => $request->user()->id,
            'study_item_id' => $row->study_item_id,
            'resource_book_item_id' => $row->id,
            'type' => $row->book->type,
            'studied_on' => $data['studiedOn'],
            'color' => $data['color'] ?? null,
            'review_on' => $data['reviewOn'] ?? null,
        ]);

        return response()->json(['data' => ['id' => $new->id]], 201);
    }

    public function destroy(Request $request, StudyRecord $record): JsonResponse
    {
        abort_unless($record->user_id === $request->user()->id, 403);
        $record->delete();

        return response()->json(['message' => 'deleted']);
    }
}
