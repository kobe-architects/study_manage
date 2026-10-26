<?php

namespace App\Http\Controllers;

use App\Models\GrammarAttempt;
use App\Models\GrammarQuestion;
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
            'answer' => ['nullable', 'string', 'max:1000'],
        ]);
        $userId = $request->user()->id;
        $attempt = GrammarAttempt::create([
            'user_id' => $userId,
            'grammar_question_id' => $question->id,
            'is_correct' => $data['isCorrect'],
            'answer' => $data['answer'] ?? null,
            'answered_at' => now(),
        ]);

        return response()->json(['data' => [
            'attemptId' => $attempt->id,
            'stat' => $this->stats($userId, [$question->id])[$question->id] ?? null,
        ]]);
    }

    /** 回答の正誤を変更する（整序・空所補充で自動採点が合わないときの自己判定） */
    public function updateAttempt(Request $request, GrammarAttempt $attempt): JsonResponse
    {
        $userId = $request->user()->id;
        abort_unless($attempt->user_id === $userId, 403);
        $data = $request->validate(['isCorrect' => ['required', 'boolean']]);
        $attempt->update(['is_correct' => $data['isCorrect']]);

        return response()->json(['data' => [
            'attemptId' => $attempt->id,
            'stat' => $this->stats($userId, [$attempt->grammar_question_id])[$attempt->grammar_question_id] ?? null,
        ]]);
    }

    /**
     * 問題ごとの回答状況。
     * @return array<int, array{correct:int, incorrect:int, last:bool|null, lastAt:string|null, lastAnswer:string|null}>
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

        $out = [];
        foreach ($rows as $r) {
            $l = $last[$r->qid] ?? null;
            $out[(int) $r->qid] = [
                'correct' => (int) $r->c,
                'incorrect' => (int) $r->n - (int) $r->c,
                'last' => $l ? (bool) $l->is_correct : null,
                'lastAt' => $l?->answered_at?->toDateTimeString(),
                'lastAnswer' => $l?->answer,
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
