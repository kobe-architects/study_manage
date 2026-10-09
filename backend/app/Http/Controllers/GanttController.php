<?php

namespace App\Http\Controllers;

use App\Models\GanttChart;
use App\Models\GanttRow;
use App\Models\GanttTask;
use App\Support\GanttExcel;
use App\Support\ImageTools;
use App\Support\PdfTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ガントチャート（数年分の学習計画）。
 * チャート → 行（科目・学習分野） → 区間（範囲学習・復習・演習・過去問 などのバー／1 日の節目）の 3 階層。
 * 画面上のドラッグで動かした結果を保存し、PDF・Excel の出力も行う。
 */
class GanttController extends Controller
{
    private const KINDS = ['task', 'milestone'];

    // ================================================================
    // チャート
    // ================================================================

    public function index(Request $request): JsonResponse
    {
        $charts = GanttChart::withCount(['rows', 'tasks'])
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
        $chart->loadCount(['rows', 'tasks']);

        return response()->json(['data' => $this->chartPayload($chart)], 201);
    }

    public function show(Request $request, GanttChart $chart): JsonResponse
    {
        $this->authorizeChart($request, $chart);

        return response()->json(['data' => $this->chartPayload($this->withRows($chart), true)]);
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

        return response()->json(['data' => $this->chartPayload($this->withRows($chart), true)]);
    }

    public function destroy(Request $request, GanttChart $chart): JsonResponse
    {
        $this->authorizeChart($request, $chart);
        $chart->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** チャートを行・区間ごと複製する（タイトル末尾に「のコピー」） */
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
            foreach ($chart->rows()->with('tasks')->get() as $row) {
                $newRow = $copy->rows()->create([
                    'title' => $row->title,
                    'group_name' => $row->group_name,
                    'sort_order' => $row->sort_order,
                ]);
                $tasks = $row->tasks->map(fn (GanttTask $t) => [
                    'gantt_chart_id' => $copy->id,
                    'gantt_row_id' => $newRow->id,
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
                if ($tasks) {
                    GanttTask::insert($tasks);
                }
            }

            return $copy;
        });
        $copy->loadCount(['rows', 'tasks']);

        return response()->json(['data' => $this->chartPayload($copy)], 201);
    }

    // ================================================================
    // 行（科目・学習分野）
    // ================================================================

    public function storeRow(Request $request, GanttChart $chart): JsonResponse
    {
        $this->authorizeChart($request, $chart);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:100'],
        ]);

        $row = $chart->rows()->create([
            'title' => $data['title'],
            'group_name' => ($data['group'] ?? '') !== '' ? $data['group'] : null,
            'sort_order' => ((int) $chart->rows()->max('sort_order')) + 1,
        ]);
        $chart->touch();

        return response()->json(['data' => $this->rowPayload($row->load('tasks'))], 201);
    }

    public function updateRow(Request $request, GanttRow $row): JsonResponse
    {
        $chart = $row->chart;
        $this->authorizeChart($request, $chart);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'group' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $attrs = [];
        if (array_key_exists('title', $data)) {
            $attrs['title'] = $data['title'];
        }
        if (array_key_exists('group', $data)) {
            $attrs['group_name'] = ($data['group'] ?? '') !== '' ? $data['group'] : null;
        }
        $row->update($attrs);
        $chart->touch();

        return response()->json(['data' => $this->rowPayload($row->fresh('tasks'))]);
    }

    /** 行の削除（行の区間もまとめて削除） */
    public function destroyRow(Request $request, GanttRow $row): JsonResponse
    {
        $chart = $row->chart;
        $this->authorizeChart($request, $chart);
        $row->delete();
        $chart->touch();

        return response()->json(['message' => 'deleted']);
    }

    /** 行の並び順を保存する（ids の順に sort_order を振り直す） */
    public function reorderRows(Request $request, GanttChart $chart): JsonResponse
    {
        $this->authorizeChart($request, $chart);
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $own = $chart->rows()->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $data['ids']), $own));
        // 指定されなかった行は末尾に元の順で残す
        $rest = array_values(array_diff($own, $ids));
        DB::transaction(function () use ($ids, $rest) {
            foreach (array_merge($ids, $rest) as $i => $id) {
                GanttRow::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });
        $chart->touch();

        return response()->json(['data' => $this->withRows($chart)->rows->map(fn (GanttRow $r) => $this->rowPayload($r))->values()]);
    }

    // ================================================================
    // 区間（バー／節目）
    // ================================================================

    public function storeTask(Request $request, GanttRow $row): JsonResponse
    {
        $chart = $row->chart;
        $this->authorizeChart($request, $chart);
        $data = $request->validate($this->taskRules(true));

        $kind = $data['kind'] ?? 'task';
        $endOn = $kind === 'milestone' ? $data['startOn'] : ($data['endOn'] ?? $data['startOn']);
        abort_if(strtotime($endOn) < strtotime($data['startOn']), 422, '終了日は開始日以降にしてください。');
        $task = $row->tasks()->create([
            'gantt_chart_id' => $chart->id,
            'title' => $data['title'],
            'kind' => $kind,
            'start_on' => $data['startOn'],
            'end_on' => $endOn,
            'color' => $data['color'] ?? '#3b50cc',
            'progress' => $data['progress'] ?? 0,
            'note' => ($data['note'] ?? '') !== '' ? $data['note'] : null,
            'sort_order' => ((int) $row->tasks()->max('sort_order')) + 1,
        ]);
        $chart->touch();

        return response()->json(['data' => $this->taskPayload($task)], 201);
    }

    /** 区間の更新。ドラッグで動かした期間（startOn/endOn）だけの部分更新にも対応 */
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

    // ================================================================
    // 出力
    // ================================================================

    /**
     * PDF 出力。画面側で描画したチャート画像（JPEG）を受け取り、A4 横 1 ページの PDF にして返す
     * （FPDF に日本語フォントが無いため、文字はすべて画面側で画像にする）。
     */
    public function pdf(Request $request, GanttChart $chart): BinaryFileResponse
    {
        $this->authorizeChart($request, $chart);
        $request->validate(['image' => ['required', 'file', 'max:25600']]);

        $disk = Storage::disk('local');
        $jpgRel = 'gantt/'.$chart->id.'/chart.jpg';
        $pdfRel = 'gantt/'.$chart->id.'/chart.pdf';
        $disk->put($jpgRel, ImageTools::normalizeJpeg((string) file_get_contents($request->file('image')->getRealPath()), 4000, 90));
        PdfTools::imagesToPdf([['path' => $disk->path($jpgRel)]], $disk->path($pdfRel));

        $name = $this->exportName($chart, 'pdf');

        return response()->file($disk->path($pdfRel), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $name, 'gantt-'.$chart->id.'.pdf'),
        ]);
    }

    /** Excel 出力（月単位のガントチャート表＋区間一覧） */
    public function excel(Request $request, GanttChart $chart): StreamedResponse
    {
        $this->authorizeChart($request, $chart);
        $binary = GanttExcel::build($this->withRows($chart));

        return response()->streamDownload(function () use ($binary) {
            echo $binary;
        }, $this->exportName($chart, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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

    private function withRows(GanttChart $chart): GanttChart
    {
        return $chart->load('rows.tasks');
    }

    private function exportName(GanttChart $chart, string $ext): string
    {
        $title = str_replace(['/', '\\', '%', '"', ':', '*', '?', '<', '>', '|'], '-', $chart->title);

        return $title.'_'.now()->format('Ymd').'.'.$ext;
    }

    private function chartPayload(GanttChart $c, bool $withRows = false): array
    {
        $out = [
            'id' => $c->id,
            'title' => $c->title,
            'note' => $c->note,
            'startOn' => $c->start_on->toDateString(),
            'endOn' => $c->end_on->toDateString(),
            'rowCount' => $withRows ? $c->rows->count() : (int) ($c->rows_count ?? 0),
            'taskCount' => $withRows ? $c->rows->sum(fn (GanttRow $r) => $r->tasks->count()) : (int) ($c->tasks_count ?? 0),
            'updatedAt' => $c->updated_at?->toDateTimeString(),
        ];
        if ($withRows) {
            $out['rows'] = $c->rows->map(fn (GanttRow $r) => $this->rowPayload($r))->values()->all();
        }

        return $out;
    }

    private function rowPayload(GanttRow $r): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'group' => $r->group_name,
            'sortOrder' => (int) $r->sort_order,
            'tasks' => $r->tasks->map(fn (GanttTask $t) => $this->taskPayload($t))->values()->all(),
        ];
    }

    private function taskPayload(GanttTask $t): array
    {
        return [
            'id' => $t->id,
            'rowId' => (int) $t->gantt_row_id,
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
