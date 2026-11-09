<?php

namespace App\Http\Controllers;

use App\Models\QuizSession;
use App\Models\ReviewState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 1 回の出題（セッション）の記録。
 * 出題開始で作り（store）、終了時に finish で回答履歴から問題数・正解数・あいまい数・解答時間の合計を集計する。
 * 一覧（index）は「出題履歴」（日時・問題数・正答率・1 問あたりの解答時間）の表示に使う。
 */
class QuizSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', ReviewState::KINDS)],
            'refId' => ['nullable', 'integer'],
            'refKey' => ['nullable', 'string', 'max:40'],
            'mode' => ['nullable', 'string', 'max:30'],
        ]);
        $s = QuizSession::create([
            'user_id' => $request->user()->id,
            'kind' => $data['kind'],
            'ref_id' => $data['refId'] ?? null,
            'ref_key' => $data['refKey'] ?? null,
            'mode' => $data['mode'] ?? null,
            'started_at' => now(),
        ]);

        return response()->json(['data' => $this->format($s)], 201);
    }

    /** 終了: 回答履歴から集計して finished_at を付ける（途中で中断したときも呼ぶ。回答が 1 つも無ければ削除） */
    public function finish(Request $request, QuizSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 403);
        $this->recount($session);
        if ($session->total === 0) {
            $session->delete();

            return response()->json(['data' => null]);
        }
        $session->finished_at ??= now();
        $session->save();

        return response()->json(['data' => $this->format($session)]);
    }

    /** 出題履歴（新しい順）。kind と refId / refKey で絞る */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', ReviewState::KINDS)],
            'refId' => ['nullable', 'integer'],
            'refKey' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $q = QuizSession::where('user_id', $request->user()->id)->where('kind', $data['kind'])->where('total', '>', 0);
        if (isset($data['refId'])) {
            $q->where('ref_id', $data['refId']);
        }
        if (! empty($data['refKey'])) {
            $q->where('ref_key', $data['refKey']);
        }
        $list = $q->orderByDesc('started_at')->limit($data['limit'] ?? 30)->get();

        return response()->json(['data' => $list->map(fn (QuizSession $s) => $this->format($s))->values()]);
    }

    /** 回答履歴から集計し直す（保存はしない） */
    public static function recount(QuizSession $session): void
    {
        $t = QuizSession::attemptTable($session->kind);
        $row = DB::table($t)->where('quiz_session_id', $session->id)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(is_correct), 0) AS c, COALESCE(SUM(is_unsure), 0) AS u, COALESCE(SUM(elapsed_ms), 0) AS ms')
            ->first();
        $session->total = (int) ($row->n ?? 0);
        $session->correct = (int) ($row->c ?? 0);
        $session->unsure = (int) ($row->u ?? 0);
        $session->elapsed_ms = (int) ($row->ms ?? 0);
    }

    public static function format(QuizSession $s): array
    {
        return [
            'id' => $s->id,
            'kind' => $s->kind,
            'refId' => $s->ref_id,
            'refKey' => $s->ref_key,
            'mode' => $s->mode,
            'startedAt' => $s->started_at?->toIso8601String(),
            'finishedAt' => $s->finished_at?->toIso8601String(),
            'total' => (int) $s->total,
            'correct' => (int) $s->correct,
            'unsure' => (int) $s->unsure,
            'elapsedMs' => (int) $s->elapsed_ms,
            'avgMs' => $s->total > 0 ? (int) round($s->elapsed_ms / $s->total) : null,
        ];
    }
}
