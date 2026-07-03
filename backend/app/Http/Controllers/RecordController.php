<?php

namespace App\Http\Controllers;

use App\Models\ResourceBookItem;
use App\Models\StudyItem;
use App\Models\StudyRecord;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecordController extends Controller
{
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

    public function destroy(Request $request, StudyRecord $record): JsonResponse
    {
        abort_unless($record->user_id === $request->user()->id, 403);
        $record->delete();

        return response()->json(['message' => 'deleted']);
    }
}
