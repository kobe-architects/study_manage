<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\ResourceBookItem;
use App\Models\StudyRecord;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 課題（家庭教師が個別学習データを選択して期限を設定）。
 * targetUserId により、生徒(owner)は自分の課題を閲覧、家庭教師(tutor)は
 * 担当生徒の課題を作成・編集・削除できる（公開ルートは routes/api.php で制限）。
 */
class AssignmentController extends Controller
{
    /** 課題一覧（進捗集計付き）。期限昇順、達成記録済みは後ろへ。 */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $today = Carbon::today();

        $assignments = Assignment::with('creator:id,name')
            ->where('user_id', $userId)
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();

        $data = $assignments->map(fn (Assignment $a) => $this->serialize($a, $userId, $today));
        $pending = $data->filter(fn ($x) => $x['achieved'] === null)->values();
        $recorded = $data->filter(fn ($x) => $x['achieved'] !== null)->values();

        return response()->json(['data' => $pending->concat($recorded)->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        // タイトルは任意（未設定時は表示側で期限をタイトルにする）
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'dueOn' => ['required', 'date'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $assignment = Assignment::create([
            'user_id' => $userId,
            'created_by' => $request->user()->id,
            'title' => $data['title'] ?? '',
            'note' => $data['note'] ?? null,
            'due_on' => $data['dueOn'],
        ]);
        $this->syncItems($assignment, $this->ownItemIds($data['ids'], $userId));

        return response()->json(['data' => ['id' => $assignment->id]], 201);
    }

    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        $userId = $this->targetUserId($request);
        abort_unless($assignment->user_id === $userId, 403);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'dueOn' => ['sometimes', 'date'],
            'achieved' => ['sometimes', 'nullable', 'boolean'],
            'ids' => ['sometimes', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $payload = [];
        foreach (['title', 'note', 'achieved'] as $k) {
            if (array_key_exists($k, $data)) {
                $payload[$k] = $data[$k];
            }
        }
        if (array_key_exists('title', $payload)) {
            $payload['title'] = $payload['title'] ?? ''; // カラムは NOT NULL のため空文字で保存
        }
        if (array_key_exists('dueOn', $data)) {
            $payload['due_on'] = $data['dueOn'];
        }
        if ($payload !== []) {
            $assignment->update($payload);
        }

        if (array_key_exists('ids', $data)) {
            $this->syncItems($assignment, $this->ownItemIds($data['ids'], $userId));
        }

        return response()->json(['data' => ['id' => $assignment->id]]);
    }

    public function destroy(Request $request, Assignment $assignment): JsonResponse
    {
        abort_unless($assignment->user_id === $this->targetUserId($request), 403);
        $assignment->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** 課題に含めた個別学習データの明細（学習済み/未学習付き） */
    public function items(Request $request, Assignment $assignment): JsonResponse
    {
        $userId = $this->targetUserId($request);
        abort_unless($assignment->user_id === $userId, 403);

        $rows = $assignment->items()->with(['book:id,title,type', 'studyItem.mid.major.subject'])->get();
        $studiedSet = array_flip($this->studiedItemIds($assignment, $userId, $rows->pluck('id')->all()));

        // 行ごとの最新学習日
        $latest = StudyRecord::where('user_id', $userId)
            ->whereIn('resource_book_item_id', $rows->pluck('id'))
            ->selectRaw('resource_book_item_id, MAX(studied_on) as last_on')
            ->groupBy('resource_book_item_id')
            ->pluck('last_on', 'resource_book_item_id');

        $data = $rows->map(function (ResourceBookItem $r) use ($studiedSet, $latest) {
            $item = $r->studyItem;
            $subject = $item?->mid?->major?->subject;

            return [
                'id' => $r->id,
                'bookTitle' => $r->book?->title,
                'type' => $r->book?->type,
                'chapter' => $r->chapter,
                'seqNo' => $r->seq_no,
                'title' => $r->title,
                'sub' => $item?->name,
                'subjectName' => $subject?->name,
                'colorVivid' => $subject?->color_vivid ?? '#475569',
                'studied' => isset($studiedSet[$r->id]),
                'studiedOn' => $latest[$r->id] ?? null,
            ];
        })
            ->sortBy(fn ($x) => $x['studied'] ? 1 : 0)
            ->values();

        return response()->json(['data' => $data]);
    }

    // ====================== 内部処理 ======================

    /**
     * 対象行の一括設定。BelongsToMany::sync() は1行ずつ INSERT するため
     * 数百行の課題で極端に遅くなる。差分を計算して一括 attach/detach する。
     */
    private function syncItems(Assignment $assignment, array $ids): void
    {
        DB::transaction(function () use ($assignment, $ids) {
            $current = $assignment->items()->pluck('resource_book_items.id')->all();
            $detach = array_values(array_diff($current, $ids));
            $attach = array_values(array_diff($ids, $current));
            if ($detach !== []) {
                $assignment->items()->detach($detach);
            }
            if ($attach !== []) {
                $assignment->items()->attach($attach); // 属性なしの attach は一括 INSERT
            }
        });
    }

    /** 生徒本人の教材行のみに絞り込む */
    private function ownItemIds(array $ids, int $userId): array
    {
        return ResourceBookItem::whereIn('resource_book_items.id', array_map('intval', $ids))
            ->whereHas('book', fn ($q) => $q->where('user_id', $userId))
            ->pluck('id')
            ->all();
    }

    /** 学習済みとみなす行ID = 課題作成後に学習記録が付いた行（goals と同じ判定） */
    private function studiedItemIds(Assignment $a, int $userId, array $linkedIds): array
    {
        if ($linkedIds === []) {
            return [];
        }

        return StudyRecord::where('user_id', $userId)
            ->whereIn('resource_book_item_id', $linkedIds)
            ->where('created_at', '>=', $a->created_at)
            ->distinct()
            ->pluck('resource_book_item_id')
            ->all();
    }

    private function serialize(Assignment $a, int $userId, Carbon $today): array
    {
        $linkedIds = $a->items()->pluck('resource_book_items.id')->all();
        $done = count($this->studiedItemIds($a, $userId, $linkedIds));
        $target = max(1, count($linkedIds));

        return [
            'id' => $a->id,
            'title' => $a->title,
            'note' => $a->note,
            'createdOn' => $a->created_at->toDateString(),
            'dueOn' => $a->due_on->toDateString(),
            'target' => $target,
            'done' => $done,
            'itemIds' => array_values($linkedIds),
            'achieved' => $a->achieved,
            'overdue' => $a->achieved === null && $a->due_on->lt($today),
            'createdByName' => $a->creator?->name,
        ];
    }
}
