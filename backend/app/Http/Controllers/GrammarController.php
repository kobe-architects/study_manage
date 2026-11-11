<?php

namespace App\Http\Controllers;

use App\Models\GrammarAttempt;
use App\Models\GrammarQuestion;
use App\Models\QuizSession;
use App\Models\ReviewState;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 英文法・語法 問題演習（スクランブル英文法・語法）。
 * 問題は全ユーザー共通のデータで、採点は画面側（正解を含めて返す）。回答履歴だけを生徒ごとに記録する。
 */
class GrammarController extends Controller
{
    public function __construct(private ReviewService $review) {}

    /** 問題集（英文法・語法）の一覧と、自分の回答状況（最新の回答で正解・不正解・未回答を数える） */
    public function books(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $totals = GrammarQuestion::query()->selectRaw('book, COUNT(*) AS total')->groupBy('book')->pluck('total', 'book');

        // 問題ごとの最新の回答を本ごとに集計
        $lastIds = GrammarAttempt::query()->where('user_id', $userId)->selectRaw('MAX(id)')->groupBy('grammar_question_id');
        $rows = GrammarAttempt::query()
            ->whereIn('grammar_attempts.id', $lastIds)
            ->join('grammar_questions', 'grammar_questions.id', '=', 'grammar_attempts.grammar_question_id')
            ->selectRaw('grammar_questions.book AS book, grammar_attempts.is_correct AS ok, COUNT(*) AS n')
            ->groupBy('grammar_questions.book', 'grammar_attempts.is_correct')
            ->get();

        // 忘却曲線の復習期限が来ている問題数（本ごと）
        $dueRows = ReviewState::query()
            ->join('grammar_questions', 'grammar_questions.id', '=', 'review_states.item_id')
            ->where('review_states.user_id', $userId)->where('review_states.kind', ReviewState::KIND_GRAMMAR)
            ->whereNotNull('review_states.due_at')->where('review_states.due_at', '<=', now())
            ->selectRaw('grammar_questions.book AS book, COUNT(*) AS n')
            ->groupBy('grammar_questions.book')
            ->pluck('n', 'book');

        $data = [];
        foreach (GrammarQuestion::BOOKS as $key => $name) {
            $total = (int) ($totals[$key] ?? 0);
            $correct = (int) $rows->where('book', $key)->where('ok', 1)->sum('n');
            $incorrect = (int) $rows->where('book', $key)->where('ok', 0)->sum('n');
            $data[] = [
                'key' => $key,
                'name' => $name,
                'total' => $total,
                'correct' => $correct,
                'incorrect' => $incorrect,
                'unanswered' => max(0, $total - $correct - $incorrect),
                'due' => (int) ($dueRows[$key] ?? 0),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** 1 冊分の問題を全件返す（絞り込み・出題順は画面側で行う）。各問題に自分の回答状況を付ける */
    public function questions(Request $request): JsonResponse
    {
        $book = (string) $request->query('book', 'grammar');
        abort_unless(isset(GrammarQuestion::BOOKS[$book]), 404);

        // 解説の本文は大きいので一覧には含めない（有無だけ。本文は explanation() で 1 問ずつ）
        $questions = GrammarQuestion::query()->where('book', $book)->orderBy('no')
            ->select(['*', DB::raw('explanation IS NOT NULL AND explanation <> "" AS has_explanation')])
            ->get();
        $stats = $this->stats($request->user()->id, $questions->pluck('id')->all());

        return response()->json([
            'data' => $questions->map(fn (GrammarQuestion $q) => $this->format($q, $stats[$q->id] ?? null))->values(),
        ]);
    }

    /** 問題・解答欄・解説の画像（PDF から切り出した JPEG。storage/app/private/grammar/ 以下） */
    public function image(GrammarQuestion $question, string $kind): BinaryFileResponse
    {
        $col = ['problem' => 'problem_image', 'answer' => 'answer_image', 'explanation' => 'explanation_image'][$kind] ?? null;
        abort_unless($col !== null && $question->{$col}, 404);
        $path = storage_path('app/private/grammar/'.$question->{$col});
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** 教材の解説ページの文章（詳細解説） */
    public function explanation(GrammarQuestion $question): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $question->id,
            'explanation' => $question->explanation,
            'explanationPage' => $question->explanation_page,
        ]]);
    }

    /** 回答を記録する */
    public function attempt(Request $request, GrammarQuestion $question): JsonResponse
    {
        $data = $request->validate([
            'isCorrect' => ['required', 'boolean'],
            'isUnsure' => ['sometimes', 'boolean'],
            'answer' => ['nullable', 'string', 'max:1000'],
            'elapsedMs' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'sessionId' => ['nullable', 'integer'],
        ]);
        $userId = $request->user()->id;
        $isCorrect = (bool) $data['isCorrect'];
        $isUnsure = $isCorrect && ! empty($data['isUnsure']);
        // 忘却曲線の復習状態を進める（不正解=もう一度 / あいまい=難しい / 正解=正解）。更新前の状態は回答に残す
        [$prev] = $this->review->applyResult($userId, ReviewState::KIND_GRAMMAR, $question->id, $isCorrect, $isUnsure);
        $attempt = GrammarAttempt::create([
            'user_id' => $userId,
            'quiz_session_id' => $this->ownSessionId($userId, $data['sessionId'] ?? null),
            'grammar_question_id' => $question->id,
            'is_correct' => $isCorrect,
            'is_unsure' => $isUnsure,
            'answer' => $data['answer'] ?? null,
            'answered_at' => now(),
            'elapsed_ms' => $data['elapsedMs'] ?? null,
            'review_prev' => ['state' => $prev],
        ]);

        return response()->json(['data' => [
            'attemptId' => $attempt->id,
            'stat' => $this->stats($userId, [$question->id])[$question->id] ?? null,
        ]]);
    }

    /**
     * 回答を変更する。
     * - isCorrect: 正誤の自己判定（整序・空所補充で自動採点が合わないとき）。不正解にしたら「あいまい」は外れる
     * - isUnsure: 「あいまい」（正解だったが自信がない）の付け外し。正解の回答にだけ付く
     */
    public function updateAttempt(Request $request, GrammarAttempt $attempt): JsonResponse
    {
        $userId = $request->user()->id;
        abort_unless($attempt->user_id === $userId, 403);
        $data = $request->validate([
            'isCorrect' => ['sometimes', 'boolean'],
            'isUnsure' => ['sometimes', 'boolean'],
        ]);
        $isCorrect = array_key_exists('isCorrect', $data) ? (bool) $data['isCorrect'] : $attempt->is_correct;
        $isUnsure = array_key_exists('isUnsure', $data) ? (bool) $data['isUnsure'] : $attempt->is_unsure;
        $attempt->update(['is_correct' => $isCorrect, 'is_unsure' => $isCorrect && $isUnsure]);
        // 復習状態を、この回答の前の状態に戻してから付け替え後の結果で進め直す
        $prev = $attempt->review_prev;
        $this->review->reapply($userId, ReviewState::KIND_GRAMMAR, $attempt->grammar_question_id, $prev['state'] ?? null, is_array($prev), $isCorrect, $isCorrect && $isUnsure);

        return response()->json(['data' => [
            'attemptId' => $attempt->id,
            'stat' => $this->stats($userId, [$attempt->grammar_question_id])[$attempt->grammar_question_id] ?? null,
        ]]);
    }

    /**
     * チェックシート用: 文法・語法（連番 1〜725）または ファーストステップ（book=first_step）の日ごとの結果。
     * 同じ日に何度か解いたときは 1 日 1 つにまとめ、1 度でも不正解なら不正解（0）、正解だけであいまいがあれば あいまい（2）、それ以外は正解（1）。
     * 日付は端末のタイムゾーン（tz = UTC からのずれ・分。既定は日本時間）で区切る。
     * 返り値: { marks: { 連番: [[YYYY-MM-DD, 0|1|2], ...] } }
     */
    public function checksheet(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $tz = max(-840, min(840, (int) $request->query('tz', 540)));
        $books = $request->query('book') === 'first_step' ? ['first_step'] : ['grammar', 'usage'];
        $rows = GrammarAttempt::query()
            ->join('grammar_questions', 'grammar_questions.id', '=', 'grammar_attempts.grammar_question_id')
            ->where('grammar_attempts.user_id', $userId)
            ->whereIn('grammar_questions.book', $books)
            ->selectRaw(
                'grammar_questions.no AS no, DATE(DATE_ADD(grammar_attempts.answered_at, INTERVAL ? MINUTE)) AS d, MIN(grammar_attempts.is_correct) AS ok, MAX(grammar_attempts.is_unsure) AS unsure',
                [$tz],
            )
            ->groupByRaw('grammar_questions.no, d')
            ->orderByRaw('grammar_questions.no, d')
            ->get();

        $marks = [];
        foreach ($rows as $r) {
            $marks[(int) $r->no][] = [(string) $r->d, (int) $r->ok === 0 ? 0 : ((int) $r->unsure ? 2 : 1)];
        }

        return response()->json(['data' => ['marks' => (object) $marks]]);
    }

    /** 自分のセッションの ID だけ受け付ける（他人・存在しない ID は無視） */
    private function ownSessionId(int $userId, ?int $id): ?int
    {
        if (! $id) {
            return null;
        }

        return QuizSession::where('id', $id)->where('user_id', $userId)->exists() ? $id : null;
    }

    /**
     * 問題ごとの回答状況。
     * @return array<int, array{correct:int, incorrect:int, last:bool|null, lastUnsure:bool, lastAt:string|null, lastAnswer:string|null}>
     */
    private function stats(int $userId, array $questionIds): array
    {
        if (! $questionIds) {
            return [];
        }
        $rows = GrammarAttempt::query()
            ->where('user_id', $userId)
            ->whereIn('grammar_question_id', $questionIds)
            ->selectRaw('grammar_question_id AS qid, SUM(is_correct) AS c, COUNT(*) AS n, MAX(id) AS last_id')
            ->groupBy('grammar_question_id')
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $last = GrammarAttempt::query()->whereIn('id', $rows->pluck('last_id'))->get()->keyBy('grammar_question_id');
        $reviews = $this->review->payloads($userId, ReviewState::KIND_GRAMMAR, $rows->pluck('qid')->map(fn ($v) => (int) $v)->all());

        $out = [];
        foreach ($rows as $r) {
            $l = $last[$r->qid] ?? null;
            $out[(int) $r->qid] = [
                'correct' => (int) $r->c,
                'incorrect' => (int) $r->n - (int) $r->c,
                'last' => $l ? (bool) $l->is_correct : null,
                'lastUnsure' => $l ? (bool) $l->is_unsure : false,
                'lastAt' => $l?->answered_at?->toDateTimeString(),
                'lastAnswer' => $l?->answer,
                'review' => $reviews[(int) $r->qid] ?? null,
            ];
        }

        return $out;
    }

    private function format(GrammarQuestion $q, ?array $stat): array
    {
        return [
            'id' => $q->id,
            'book' => $q->book,
            'no' => $q->no,
            'chapterNo' => $q->chapter_no,
            'chapter' => $q->chapter,
            'theme' => $q->theme,
            'format' => $q->format,
            'formatLabel' => $q->format_label,
            'importance' => $q->importance,
            'instruction' => $q->instruction,
            'question' => $q->question,
            'choices' => $q->choices,
            'answerIndex' => $q->answer_index,
            'answerText' => $q->answer_text,
            'answers' => $q->answers,
            'answerNote' => $q->answer_note,
            'missing' => $q->missing ?: [],
            'unneeded' => $q->unneeded ?: [],
            'translation' => $q->translation,
            'source' => $q->source,
            'note' => $q->note,
            'hasExplanation' => (bool) ($q->has_explanation ?? ($q->explanation !== null && $q->explanation !== '')) || $q->explanation_image !== null,
            'choiceCount' => $q->choice_count,
            'hasProblemImage' => $q->problem_image !== null,
            'hasAnswerImage' => $q->answer_image !== null,
            'hasExplanationImage' => $q->explanation_image !== null,
            'stat' => $stat,
        ];
    }
}
