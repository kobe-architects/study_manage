<?php

namespace App\Http\Controllers;

use App\Models\GanttChart;
use App\Models\GanttTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ガントチャート（数年分の学習計画）。チャートを複数作成・管理し、項目（バー／マイルストーン）を
 * 画面上のドラッグで動かした結果を保存する。
 */
class GanttController extends Controller
{
    private const KINDS = ['task', 'milestone'];

    // ================================================================
    // チャート
    // ================================================================

    public function index(Request $request): JsonResponse
    {
        $charts = GanttChart::withCount('tasks')
            ->where('user_id', $this->targetUserId($request))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $charts->map(fn (GanttChart $c) => $this->chartPayload($c))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'startOn' => ['required', 'date'],
            'endOn' => ['required', 'date', 'after_or_equal:startOn'],
        ]);

        $chart = GanttChart::create([
            'user_id' => $this->targetUserId($request),
            'title' => $data['title'],
            'note' => ($data['note'] ?? '') !== '' ? $data['note'] : null,
            'start_on' => $data['startOn'],
            'end_on' => $data['endOn'],
        ]);
        $chart->loadCount('tasks');

        return response()->json(['data' => $this->chartPayload($chart)], 201);
    }

    public function show(Request $request, GanttChart $chart): JsonResponse
    {
        $this->authorizeChart($request, $chart);
        $chart->load('tasks');

        return response()->json(['data' => $this->chartPayload($chart, true)]);
    }

    public function update(Request $request, GanttChart $chart): JsonResponse
    {
        $this->authorizeChart($request, $chart);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'startOn' => ['sometimes', 'required', 'date'],
            'endOn' => ['sometimes', 'required', 'date'],
        ]);

        $attrs = [];
        if (array_key_exists('title', $data)) {
            $attrs['title'] = $data['title'];
        }
        if (array_key_exists('note', $data)) {
            $attrs['note'] = ($data['note'] ?? '') !== '' ? $data['note'] : null;
        }
        if (array_key_exists('startOn', $data)) {
            $attrs['start_on'] = $data['startOn'];
        }
        if (array_key_exists('endOn', $data)) {
            $attrs['end_on'] = $data['endOn'];
        }
        $chart->fill($attrs);
        abort_if($chart->end_on->lt($chart->start_on), 422, '終了日は開始日以降にしてください。');
        $chart->save();
        $chart->load('tasks');

        return response()->json(['data' => $this->chartPayload($chart, true)]);
    }

    public function destroy(Request $request, GanttChart $chart): JsonResponse
    {
        $this->authorizeChart($request, $chart);
        $chart->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** チャートを項目ごと複製する（タイトル末尾に「のコピー」） */
    public function duplicate(Request $request, GanttChart $chart): JsonResponse
    {
        $this->authorizeChart($request, $chart);

        $copy = DB::transaction(function () use ($chart) {
            $copy = GanttChart::create([
                'user_id' => $chart->user_id,
                'title' => mb_substr($chart->title.'のコピー', 0, 255),
                'note' => $chart->note,
                'start_on' => $chart->start_on,
                'end_on' => $chart->end_on,
            ]);
            $now = now();
            $rows = $chart->tasks()->get()->map(fn (GanttTask $t) => [
                'gantt_chart_id' => $copy->id,
                'title' => $t->title,
                'kind' => $t->kind,
                'start_on' => $t->start_on->toDateString(),
                'end_on' => $t->end_on->toDateString(),
                'color' => $t->color,
                'progress' => $t->progress,
                'note' => $t->note,
                'sort_order' => $t->sort_order,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            if ($rows) {
                GanttTask::insert($rows);
            }

            return $copy;
        });
        $copy->loadCount('tasks');

        return response()->json(['data' => $this->chartPayload($copy)], 201);
    }

    // ================================================================
    // 項目（バー／マイルストーン）
    // ================================================================

    public function storeTask(Request $request, GanttChart $chart): JsonResponse
    {
        $this->authorizeChart($request, $chart);
        $data = $request->validate($this->taskRules(true));

        $kind = $data['kind'] ?? 'task';
        $endOn = $kind === 'milestone' ? $data['startOn'] : ($data['endOn'] ?? $data['startOn']);
        abort_if(strtotime($endOn) < strtotime($data['startOn']), 422, '終了日は開始日以降にしてください。');
        $task = $chart->tasks()->create([
            'title' => $data['title'],
            'kind' => $kind,
            'start_on' => $data['startOn'],
            'end_on' => $endOn,
            'color' => $data['color'] ?? '#3b50cc',
            'progress' => $data['progress'] ?? 0,
            'note' => ($data['note'] ?? '') !== '' ? $data['note'] : null,
            'sort_order' => ((int) $chart->tasks()->max('sort_order')) + 1,
        ]);
        $chart->touch();

        return response()->json(['data' => $this->taskPayload($task)], 201);
    }

    /** 項目の更新。ドラッグで動かした期間（startOn/endOn）だけの部分更新にも対応 */
    public function updateTask(Request $request, GanttTask $task): JsonResponse
    {
        $chart = $task->chart;
        $this->authorizeChart($request, $chart);
        $data = $request->validate($this->taskRules(false));

        $attrs = [];
        $map = ['title' => 'title', 'kind' => 'kind', 'startOn' => 'start_on', 'endOn' => 'end_on', 'color' => 'color', 'progress' => 'progress'];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $data) && $data[$in] !== null) {
                $attrs[$col] = $data[$in];
            }
        }
        if (array_key_exists('note', $data)) {
            $attrs['note'] = ($data['note'] ?? '') !== '' ? $data['note'] : null;
        }
        $task->fill($attrs);
        if ($task->kind === 'milestone') {
            $task->end_on = $task->start_on;
        }
        abort_if($task->end_on->lt($task->start_on), 422, '終了日は開始日以降にしてください。');
        $task->save();
        $chart->touch();

        return response()->json(['data' => $this->taskPayload($task->fresh())]);
    }

    public function destroyTask(Request $request, GanttTask $task): JsonResponse
    {
        $chart = $task->chart;
        $this->authorizeChart($request, $chart);
        $task->delete();
        $chart->touch();

        return response()->json(['message' => 'deleted']);
    }

    /** 項目の並び順を保存する（ids の順に sort_order を振り直す） */
    public function reorderTasks(Request $request, GanttChart $chart): JsonResponse
    {
        $this->authorizeChart($request, $chart);
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $own = $chart->tasks()->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $data['ids']), $own));
        // 指定されなかった項目は末尾に元の順で残す
        $rest = array_values(array_diff($own, $ids));
        DB::transaction(function () use ($ids, $rest) {
            foreach (array_merge($ids, $rest) as $i => $id) {
                GanttTask::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });
        $chart->touch();
        $chart->load('tasks');

        return response()->json(['data' => $chart->tasks->map(fn (GanttTask $t) => $this->taskPayload($t))->values()]);
    }

    // ================================================================
    // 内部
    // ================================================================

    private function taskRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:255'],
            'kind' => ['sometimes', 'string', 'in:'.implode(',', self::KINDS)],
            'startOn' => [$req, 'date'],
            'endOn' => [$create ? 'required_unless:kind,milestone' : 'sometimes', 'nullable', 'date'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    private function authorizeChart(Request $request, GanttChart $chart): void
    {
        abort_unless($chart->user_id === $this->targetUserId($request), 403);
    }

    private function chartPayload(GanttChart $c, bool $withTasks = false): array
    {
        $out = [
            'id' => $c->id,
            'title' => $c->title,
            'note' => $c->note,
            'startOn' => $c->start_on->toDateString(),
            'endOn' => $c->end_on->toDateString(),
            'taskCount' => $withTasks ? $c->tasks->count() : (int) ($c->tasks_count ?? 0),
            'updatedAt' => $c->updated_at?->toDateTimeString(),
        ];
        if ($withTasks) {
            $out['tasks'] = $c->tasks->map(fn (GanttTask $t) => $this->taskPayload($t))->values()->all();
        }

        return $out;
    }

    private function taskPayload(GanttTask $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'kind' => $t->kind,
            'startOn' => $t->start_on->toDateString(),
            'endOn' => $t->end_on->toDateString(),
            'color' => $t->color,
            'progress' => (int) $t->progress,
            'note' => $t->note,
            'sortOrder' => (int) $t->sort_order,
        ];
    }
}
