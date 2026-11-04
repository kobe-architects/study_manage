<?php

namespace App\Http\Controllers;

use App\Models\StudyTask;
use App\Models\StudyTaskLog;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * タスク管理（生徒）。
 * 科目ごとの「やるべきこと」を 1 日あたり／1 週あたりの量で登録し（例: 英単語 LEAP basic 140 words / week）、
 * 日別・週別の計画への展開と消化量の記録はトップページで行う。日割りの計算は画面側（lib/tasks.ts）。
 */
class StudyTaskController extends Controller
{
    private const PERIODS = ['day', 'week'];

    /** タスク一覧と科目一覧（追加・編集フォームの選択肢） */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $tasks = StudyTask::with('subject:id,name,color_soft,color_vivid,sort_order')
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $subjects = Subject::where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'color_soft', 'color_vivid']);

        return response()->json(['data' => [
            'tasks' => $tasks->map(fn (StudyTask $t) => $this->payload($t))->values(),
            'subjects' => $subjects->map(fn (Subject $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'colorSoft' => $s->color_soft,
                'colorVivid' => $s->color_vivid,
            ])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $this->validateTask($request, $userId);

        $max = (int) StudyTask::where('user_id', $userId)->max('sort_order');
        $task = StudyTask::create([
            'user_id' => $userId,
            'sort_order' => $max + 1,
            'is_active' => true,
            ...$this->attributes($data),
        ]);

        return response()->json(['data' => $this->payload($task->load('subject'))]);
    }

    public function update(Request $request, StudyTask $task): JsonResponse
    {
        $userId = $this->targetUserId($request);
        abort_unless($task->user_id === $userId, 403);
        $data = $this->validateTask($request, $userId, true);
        $task->update($this->attributes($data, $task));

        return response()->json(['data' => $this->payload($task->fresh(['subject']))]);
    }

    public function destroy(Request $request, StudyTask $task): JsonResponse
    {
        abort_unless($task->user_id === $this->targetUserId($request), 403);
        $task->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** 並び順を ID の配列で一括更新 */
    public function reorder(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        $owned = StudyTask::where('user_id', $userId)->whereIn('id', $data['ids'])->pluck('id')->all();
        $set = array_flip($owned);
        DB::transaction(function () use ($data, $set) {
            $order = 0;
            foreach ($data['ids'] as $id) {
                if (! isset($set[$id])) {
                    continue;
                }
                StudyTask::where('id', $id)->update(['sort_order' => $order++]);
            }
        });

        return response()->json(['message' => 'ok']);
    }

    /** 期間内の消化量（タスク × 日付） */
    public function logs(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $logs = StudyTaskLog::whereHas('task', fn ($q) => $q->where('user_id', $userId))
            ->whereBetween('date', [$data['from'], $data['to']])
            ->orderBy('date')
            ->get();

        return response()->json(['data' => $logs->map(fn (StudyTaskLog $l) => [
            'taskId' => $l->study_task_id,
            'date' => $l->date->toDateString(),
            'amount' => $l->amount,
        ])->values()]);
    }

    /** その日の消化量を上書き保存（0 は行を消す） */
    public function saveLog(Request $request, StudyTask $task): JsonResponse
    {
        abort_unless($task->user_id === $this->targetUserId($request), 403);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $date = substr((string) $data['date'], 0, 10);
        if ((int) $data['amount'] === 0) {
            StudyTaskLog::where('study_task_id', $task->id)->where('date', $date)->delete();
        } else {
            StudyTaskLog::updateOrCreate(
                ['study_task_id' => $task->id, 'date' => $date],
                ['amount' => (int) $data['amount']],
            );
        }

        return response()->json(['data' => ['taskId' => $task->id, 'date' => $date, 'amount' => (int) $data['amount']]]);
    }

    // ----------------------------------------------------------------

    private function validateTask(Request $request, int $userId, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'max:255'],
            'subjectId' => ['nullable', 'integer'],
            'amount' => [$req, 'integer', 'min:1', 'max:1000000'],
            'unit' => [$req, 'string', 'max:30'],
            'period' => [$req, 'string', 'in:'.implode(',', self::PERIODS)],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'min:0', 'max:6'],
            'startOn' => [$partial ? 'sometimes' : 'nullable', 'date'],
            'endOn' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'isActive' => ['nullable', 'boolean'],
        ]) + ['_userId' => $userId];
    }

    /** 検証済みの入力をカラム名に写す。部分更新では渡されたキーだけ */
    private function attributes(array $data, ?StudyTask $current = null): array
    {
        $userId = $data['_userId'];
        $attrs = [];
        if (array_key_exists('title', $data)) {
            $attrs['title'] = trim($data['title']);
        }
        if (array_key_exists('subjectId', $data)) {
            $sid = $data['subjectId'];
            // 自分の科目だけ紐づけられる（他人の科目 ID は「科目なし」にする）
            $attrs['subject_id'] = $sid !== null && Subject::where('user_id', $userId)->where('id', $sid)->exists() ? (int) $sid : null;
        }
        if (array_key_exists('amount', $data)) {
            $attrs['amount'] = (int) $data['amount'];
        }
        if (array_key_exists('unit', $data)) {
            $attrs['unit'] = trim($data['unit']);
        }
        if (array_key_exists('period', $data)) {
            $attrs['period'] = $data['period'];
        }
        if (array_key_exists('weekdays', $data)) {
            $wd = $data['weekdays'];
            if (is_array($wd)) {
                $wd = array_values(array_unique(array_map('intval', $wd)));
                sort($wd);
                // 7 曜日すべて＝毎日は null にそろえる
                if (count($wd) === 0 || count($wd) === 7) {
                    $wd = null;
                }
            }
            $attrs['weekdays'] = $wd;
        }
        if (array_key_exists('startOn', $data) || $current === null) {
            $attrs['start_on'] = isset($data['startOn']) ? substr((string) $data['startOn'], 0, 10) : now()->toDateString();
        }
        if (array_key_exists('endOn', $data)) {
            $attrs['end_on'] = $data['endOn'] !== null && $data['endOn'] !== '' ? substr((string) $data['endOn'], 0, 10) : null;
        }
        if (array_key_exists('note', $data)) {
            $attrs['note'] = ($data['note'] ?? '') !== '' ? $data['note'] : null;
        }
        if (array_key_exists('isActive', $data)) {
            $attrs['is_active'] = (bool) $data['isActive'];
        }
        // 終了日が開始日より前なら無期限にする
        $start = $attrs['start_on'] ?? $current?->start_on?->toDateString();
        $end = array_key_exists('end_on', $attrs) ? $attrs['end_on'] : $current?->end_on?->toDateString();
        if ($start !== null && $end !== null && $end < $start) {
            $attrs['end_on'] = null;
        }

        return $attrs;
    }

    private function payload(StudyTask $t): array
    {
        return [
            'id' => $t->id,
            'subjectId' => $t->subject_id,
            'subjectName' => $t->subject?->name,
            'colorSoft' => $t->subject?->color_soft ?? '#64748b',
            'colorVivid' => $t->subject?->color_vivid ?? '#475569',
            'title' => $t->title,
            'amount' => (int) $t->amount,
            'unit' => $t->unit,
            'period' => $t->period,
            'weekdays' => $t->weekdays,
            'startOn' => $t->start_on->toDateString(),
            'endOn' => $t->end_on?->toDateString(),
            'note' => $t->note,
            'isActive' => (bool) $t->is_active,
            'sortOrder' => (int) $t->sort_order,
        ];
    }
}
