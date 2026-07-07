<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\ResourceBook;
use App\Models\ResourceBookItem;
use App\Models\StudyItem;
use App\Models\StudyRecord;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class GoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        // 親目標のみ取得し、中間目標(children)は入れ子で返す
        $goals = Goal::with('subject')
            ->where('user_id', $userId)
            ->whereNull('parent_id')
            ->orderBy('deadline')
            ->get();

        $data = $goals->map(fn (Goal $g) => $this->serializeGoal($g, $userId));

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subjectId' => ['nullable', 'integer'],
            'scope' => ['nullable', 'string', 'max:150'],
            'rangeLabel' => ['nullable', 'string', 'max:150'],
            'deadline' => ['required', 'date'],
            'target' => ['required', 'integer', 'min:1'],
        ]);

        $goal = Goal::create([
            'user_id' => $userId,
            'subject_id' => $data['subjectId'] ?? null,
            'title' => $data['title'],
            'scope' => $data['scope'] ?? 'all',
            'range_label' => $data['rangeLabel'] ?? ($data['scope'] ?? 'all'),
            'deadline' => $data['deadline'],
            'target' => $data['target'],
        ]);

        return response()->json(['data' => ['id' => $goal->id]], 201);
    }

    /** 中間目標の作成。期限は親以前、対象は親の紐づけ項目のサブセットに限定する。 */
    public function storeSubGoal(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        abort_if($goal->parent_id !== null, 422, '中間目標に対して中間目標は作成できません。');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'deadline' => ['required', 'date', 'before_or_equal:'.$goal->deadline->toDateString()],
            'ids' => ['present', 'array'],
            'ids.*' => ['integer'],
        ]);

        // 親の紐づけ項目のサブセットに限定
        $parentIds = $goal->items()->pluck('resource_book_items.id')->all();
        $ids = array_values(array_intersect(array_map('intval', $data['ids']), $parentIds));

        $sub = Goal::create([
            'user_id' => $goal->user_id,
            'parent_id' => $goal->id,
            'subject_id' => $goal->subject_id,
            'title' => $data['title'],
            'scope' => $goal->scope,
            'range_label' => $goal->range_label,
            'deadline' => $data['deadline'],
            'target' => max(1, count($ids)),
        ]);
        $sub->items()->sync($ids);

        // 親で既に学習済みの項目は中間目標でも学習済みで初期化（使いやすさ）
        $parentStudied = $this->studiedItemIds($goal, $request->user()->id);
        foreach (array_intersect($ids, $parentStudied) as $iid) {
            $sub->items()->updateExistingPivot($iid, ['studied' => true]);
        }
        $this->syncGoalMeta($sub);

        return response()->json(['data' => ['id' => $sub->id]], 201);
    }

    /** 目標の更新（達成/未達成の記録など） */
    public function update(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'deadline' => ['sometimes', 'date'],
            'target' => ['sometimes', 'integer', 'min:1'],
            'achieved' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $payload = [];
        foreach (['title', 'deadline', 'target'] as $k) {
            if (array_key_exists($k, $data)) {
                $payload[$k] = $data[$k];
            }
        }
        if (array_key_exists('achieved', $data)) {
            $payload['achieved'] = $data['achieved'];
        }
        $goal->update($payload);

        return response()->json(['data' => ['id' => $goal->id]]);
    }

    /** 紐づける個別学習データ（教材の行）の一括設定（sync）。自ユーザーの行のみ受け付ける */
    public function updateItems(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'ids' => ['present', 'array'],
            'ids.*' => ['integer'],
        ]);

        $ownIds = ResourceBookItem::whereIn('resource_book_items.id', $data['ids'])
            ->whereHas('book', fn ($q) => $q->where('user_id', $request->user()->id))
            ->pluck('id')
            ->all();

        // 中間目標では親のサブセットに限定
        if ($goal->parent_id) {
            $parentIds = $goal->parent?->items()->pluck('resource_book_items.id')->all() ?? [];
            $ownIds = array_values(array_intersect($ownIds, $parentIds));
        }

        $goal->items()->sync($ownIds); // sync は既存 pivot(studied) を保持
        $this->syncGoalMeta($goal);

        return response()->json(['data' => ['linkedCount' => count($ownIds)]]);
    }

    /** 紐づけ項目の「学習済み」を手動で設定（トップ/目標画面からの直接設定）。中間目標→親へ波及。 */
    public function setItemStudied(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'itemId' => ['required', 'integer'],
            'studied' => ['required', 'boolean'],
        ]);

        abort_unless(
            $goal->items()->where('resource_book_items.id', $data['itemId'])->exists(),
            422,
            'この項目は目標に紐づいていません。'
        );

        $goal->items()->updateExistingPivot($data['itemId'], ['studied' => $data['studied']]);

        // 中間目標で学習済みにした項目は元の目標へも波及
        if ($goal->parent_id && $data['studied']) {
            $parent = $goal->parent;
            if ($parent && $parent->items()->where('resource_book_items.id', $data['itemId'])->exists()) {
                $parent->items()->updateExistingPivot($data['itemId'], ['studied' => true]);
            }
        }

        return response()->json(['message' => 'ok']);
    }

    /** 紐づけモーダル用のツリー: 教材 → 章 → 行（全教材） */
    public function linkOptions(Request $request): JsonResponse
    {
        $books = ResourceBook::with(['subject', 'items'])
            ->where('user_id', $request->user()->id)
            ->orderBy('type')->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json(['data' => $this->buildBookTree($books)]);
    }

    /** 中間目標の紐づけ用ツリー: 親目標の紐づけ項目のみに限定 */
    public function subLinkOptions(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $itemIds = $goal->items()->pluck('resource_book_items.id')->all();

        $books = ResourceBook::with(['subject', 'items' => fn ($q) => $q->whereIn('resource_book_items.id', $itemIds)])
            ->where('user_id', $request->user()->id)
            ->whereHas('items', fn ($q) => $q->whereIn('resource_book_items.id', $itemIds))
            ->orderBy('type')->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json(['data' => $this->buildBookTree($books)]);
    }

    /** 目標に紐づく個別学習データの明細（学習済み/未学習付き） */
    public function linkedItems(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $userId = $request->user()->id;

        $rows = $goal->items()->with(['book:id,title,type', 'studyItem.mid.major.subject'])->get();
        $studiedSet = array_flip($this->studiedItemIds($goal, $userId, $rows));

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

    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $goal->delete(); // 子（中間目標）は FK cascade で削除

        return response()->json(['message' => 'deleted']);
    }

    // ====================== 内部処理 ======================

    /** 目標を配列化（done は studied 判定に基づく）。$withChildren=true で中間目標を入れ子に含める */
    private function serializeGoal(Goal $g, int $userId, bool $withChildren = true): array
    {
        $items = $g->items()->get();
        $linkedIds = $items->pluck('id');
        $studiedIds = $this->studiedItemIds($g, $userId, $items);
        $done = $linkedIds->isEmpty() ? $this->computeDone($g, $userId) : count($studiedIds);

        $data = [
            'id' => $g->id,
            'parentId' => $g->parent_id,
            'title' => $g->title,
            'subjectId' => $g->subject_id,
            'subjectName' => $g->subject?->name,
            'colorSoft' => $g->subject?->color_soft ?? '#475569',
            'colorVivid' => $g->subject?->color_vivid ?? '#475569',
            'scope' => $g->scope,
            'rangeLabel' => $g->range_label ?? $g->scope,
            'createdOn' => $g->created_at->toDateString(),
            'deadline' => $g->deadline->toDateString(),
            'target' => $g->target,
            'done' => $done,
            'itemIds' => $linkedIds->values(),
            'linkedCount' => $linkedIds->count(),
            'achieved' => $g->achieved,
            'subGoals' => [],
        ];

        if ($withChildren) {
            $children = $g->children()->with('subject')->orderBy('deadline')->get();
            $data['subGoals'] = $children->map(fn (Goal $c) => $this->serializeGoal($c, $userId, false))->all();
        }

        return $data;
    }

    /**
     * 「学習済み」とみなす紐づけ行IDの集合。
     * = 手動 学習済み(pivot.studied) ∪ 目標設定後(goal.created_at 以降)に記録された行。
     *
     * @param  EloquentCollection<int, ResourceBookItem>|null  $items
     * @return array<int, int>
     */
    private function studiedItemIds(Goal $goal, int $userId, ?EloquentCollection $items = null): array
    {
        $items ??= $goal->items()->get();
        $linkedIds = $items->pluck('id')->all();
        if (empty($linkedIds)) {
            return [];
        }

        $manual = $items->filter(fn ($r) => $r->pivot->studied)->pluck('id')->all();

        $auto = StudyRecord::where('user_id', $userId)
            ->whereIn('resource_book_item_id', $linkedIds)
            ->where('created_at', '>=', $goal->created_at)
            ->distinct()
            ->pluck('resource_book_item_id')
            ->all();

        return array_values(array_unique(array_merge($manual, $auto)));
    }

    /** @param  EloquentCollection<int, ResourceBook>  $books */
    private function buildBookTree(EloquentCollection $books): array
    {
        return $books->map(function (ResourceBook $b) {
            $chapters = [];
            foreach ($b->items as $r) {
                $key = $r->chapter ?: '（章未設定）';
                $chapters[$key][] = [
                    'id' => $r->id,
                    'seqNo' => $r->seq_no,
                    'title' => $r->title,
                    'checkFlag' => $r->check_flag,
                    'think' => $r->meta['Think'] ?? null,
                ];
            }

            return [
                'id' => $b->id,
                'title' => $b->title,
                'type' => $b->type,
                'subjectName' => $b->subject?->name,
                'colorVivid' => $b->subject?->color_vivid ?? '#475569',
                'rowCount' => $b->items->count(),
                'chapters' => collect($chapters)->map(fn ($rows, $name) => ['name' => $name, 'rows' => $rows])->values(),
            ];
        })->values()->all();
    }

    /** 紐づけ先（教材の行）から目標の科目・範囲ラベル・項目数(target)を反映する */
    private function syncGoalMeta(Goal $goal): void
    {
        $items = $goal->items()->with('book.subject')->get();
        if ($items->isEmpty()) {
            $goal->update(['subject_id' => null, 'range_label' => '個別学習データ']);

            return;
        }

        $subjectId = $items
            ->map(fn ($r) => $r->book?->subject_id)
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        $bookTitles = $items->map(fn ($r) => $r->book?->title)->filter()->unique()->values();
        $label = $bookTitles->count() === 1
            ? $bookTitles->first()
            : ($subjectId ? (Subject::find($subjectId)?->name ?? '個別学習データ').' 他' : '個別学習データ');

        // 進める項目数（target）＝紐づけたデータ数
        $goal->update(['subject_id' => $subjectId, 'range_label' => $label, 'target' => max(1, $items->count())]);
    }

    /**
     * 未紐づけ目標の達成項目数（後方互換）: 範囲(科目/大分類)内で学習記録が1件以上ある小分類の数。
     */
    private function computeDone(Goal $goal, int $userId, ?Collection $itemIds = null): int
    {
        if ($itemIds && $itemIds->isNotEmpty()) {
            return StudyRecord::where('user_id', $userId)
                ->whereIn('resource_book_item_id', $itemIds->all())
                ->distinct()
                ->count('resource_book_item_id');
        }

        if (! $goal->subject_id) {
            return 0;
        }

        $query = StudyItem::query()
            ->whereHas('mid.major', fn ($q) => $q->where('subject_id', $goal->subject_id))
            ->whereHas('records', fn ($q) => $q->where('user_id', $userId));

        if ($goal->scope && $goal->scope !== 'all') {
            $query->whereHas('mid.major', fn ($q) => $q->where('name', $goal->scope));
        }

        return $query->count();
    }
}
