<?php

namespace App\Http\Controllers;

use App\Models\MidCategory;
use App\Models\StudyItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudyItemController extends Controller
{
    /**
     * 学習項目のフラット一覧（科目→大分類→中分類→小分類のパス + 学習集計）。
     * 進捗ダッシュボード・データ一覧・進捗対象ツリー・記録フォームの全てを賄う。
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $items = StudyItem::query()
            ->with('mid.major.subject')
            ->whereHas('mid.major.subject', fn ($q) => $q->where('user_id', $userId))
            ->orderBy('id')
            ->get();

        // 行ベース集計: 進捗対象(included)かつ学習項目に紐づく行のみを小分類×種別で集計
        $totals = DB::table('resource_book_items as rbi')
            ->join('resource_books as rb', 'rb.id', '=', 'rbi.resource_book_id')
            ->where('rb.user_id', $userId)
            ->where('rbi.included', true)
            ->whereNotNull('rbi.study_item_id')
            ->groupBy('rbi.study_item_id', 'rb.type')
            ->selectRaw('rbi.study_item_id as item_id, rb.type as type, COUNT(*) as total')
            ->get();

        // 1件以上記録のある行数 / 最終学習日（進捗対象の行のみ・小分類×種別）
        $dones = DB::table('study_records as sr')
            ->join('resource_book_items as rbi', 'rbi.id', '=', 'sr.resource_book_item_id')
            ->where('sr.user_id', $userId)
            ->where('rbi.included', true)
            ->groupBy('sr.study_item_id', 'sr.type')
            ->selectRaw('sr.study_item_id as item_id, sr.type as type, COUNT(DISTINCT sr.resource_book_item_id) as done, MAX(sr.studied_on) as last_date')
            ->get();

        $totalMap = [];
        foreach ($totals as $t) {
            $totalMap[$t->item_id][$t->type] = (int) $t->total;
        }
        $doneMap = [];
        foreach ($dones as $d) {
            $doneMap[$d->item_id][$d->type] = ['done' => (int) $d->done, 'last' => $d->last_date];
        }

        $byTypeFor = function (int $itemId) use ($totalMap, $doneMap) {
            $out = [];
            foreach (['講義', '問題集', '教科書'] as $type) {
                $done = $doneMap[$itemId][$type] ?? ['done' => 0, 'last' => null];
                $out[$type] = [
                    'total' => $totalMap[$itemId][$type] ?? 0,
                    'done' => $done['done'],
                    'lastDate' => $done['last'],
                ];
            }

            return $out;
        };

        $rows = $items->map(function (StudyItem $item) use ($byTypeFor) {
            $subject = $item->mid->major->subject;

            return [
                'id' => $item->id,
                'subjectId' => $subject->id,
                'subjectCode' => $subject->code,
                'subjectName' => $subject->name,
                'group' => $subject->group_name,
                'colorSoft' => $subject->color_soft,
                'colorVivid' => $subject->color_vivid,
                'majorId' => $item->mid->major->id,
                'major' => $item->mid->major->name,
                'midId' => $item->mid->id,
                'mid' => $item->mid->name,
                'sub' => $item->name,
                'sortOrder' => $item->sort_order,
                'included' => $item->included,
                'byType' => $byTypeFor($item->id),
            ];
        });

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'midCategoryId' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $mid = MidCategory::whereHas('major.subject', fn ($q) => $q->where('user_id', $userId))
            ->findOrFail($data['midCategoryId']);

        $sortOrder = (int) $mid->items()->max('sort_order') + 1;
        $item = StudyItem::create([
            'mid_category_id' => $mid->id,
            'name' => $data['name'],
            'sort_order' => $sortOrder,
            'included' => true,
        ]);

        return response()->json(['data' => ['id' => $item->id]], 201);
    }

    public function update(Request $request, StudyItem $studyItem): JsonResponse
    {
        $this->authorizeItem($request, $studyItem);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);
        $studyItem->update(['name' => $data['name']]);

        return response()->json(['data' => ['id' => $studyItem->id]]);
    }

    public function destroy(Request $request, StudyItem $studyItem): JsonResponse
    {
        $this->authorizeItem($request, $studyItem);
        $studyItem->delete();

        return response()->json(['message' => 'deleted']);
    }

    /**
     * 進捗対象（included）の一括更新。ツリーのチェック操作で使用。
     */
    public function updateIncluded(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'included' => ['required', 'boolean'],
        ]);

        StudyItem::query()
            ->whereIn('id', $data['ids'])
            ->whereHas('mid.major.subject', fn ($q) => $q->where('user_id', $userId))
            ->update(['included' => $data['included']]);

        return response()->json(['message' => 'updated']);
    }

    private function authorizeItem(Request $request, StudyItem $item): void
    {
        abort_unless(
            $item->mid->major->subject->user_id === $request->user()->id,
            403
        );
    }
}
