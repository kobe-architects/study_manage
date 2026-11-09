<?php

namespace App\Http\Controllers;

use App\Models\CardAttempt;
use App\Models\CardDeck;
use App\Models\CardItem;
use App\Models\QuizSession;
use App\Models\ReviewState;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 学習カード（鉄緑会 英文法の「覚える表現」「例文」、合格る確率・合格る計算の問題）。
 * カードは全ユーザー共通のデータ（php artisan cards:import で登録）。回答履歴と復習状態だけ生徒ごと。
 * 採点は画面側（自己判定・空所補充の照合）で行い、結果だけを記録する。生徒（owner）専用。
 */
class CardController extends Controller
{
    public function __construct(private ReviewService $review) {}

    /** 束の一覧（subject で絞る）と自分の回答状況（最新の回答で正解・不正解・未回答、復習期限） */
    public function decks(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $subject = $request->query('subject');
        $decks = CardDeck::query()->withCount('items')
            ->when($subject, fn ($q) => $q->where('subject', $subject))
            ->orderBy('sort_order')->orderBy('id')->get();
        if ($decks->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $lastIds = CardAttempt::query()->where('user_id', $userId)->selectRaw('MAX(id)')->groupBy('card_item_id');
        $rows = CardAttempt::query()
            ->whereIn('card_attempts.id', $lastIds)
            ->join('card_items', 'card_items.id', '=', 'card_attempts.card_item_id')
            ->selectRaw('card_items.card_deck_id AS deck_id, card_attempts.is_correct AS ok, COUNT(*) AS n')
            ->groupBy('card_items.card_deck_id', 'card_attempts.is_correct')
            ->get();
        $due = ReviewState::query()
            ->join('card_items', 'card_items.id', '=', 'review_states.item_id')
            ->where('review_states.user_id', $userId)->where('review_states.kind', ReviewState::KIND_CARD)
            ->whereNotNull('review_states.due_at')->where('review_states.due_at', '<=', now())
            ->selectRaw('card_items.card_deck_id AS deck_id, COUNT(*) AS n')
            ->groupBy('card_items.card_deck_id')
            ->pluck('n', 'deck_id');

        $data = $decks->map(function (CardDeck $d) use ($rows, $due) {
            $correct = (int) $rows->where('deck_id', $d->id)->where('ok', 1)->sum('n');
            $incorrect = (int) $rows->where('deck_id', $d->id)->where('ok', 0)->sum('n');

            return [
                'id' => $d->id,
                'key' => $d->key,
                'subject' => $d->subject,
                'name' => $d->name,
                'shortName' => $d->short_name ?? $d->name,
                'studentOnly' => (bool) $d->student_only,
                'resourceBookTitle' => $d->resource_book_title,
                'structure' => $d->structure,
                'total' => (int) $d->items_count,
                'correct' => $correct,
                'incorrect' => $incorrect,
                'unanswered' => max(0, (int) $d->items_count - $correct - $incorrect),
                'due' => (int) ($due[$d->id] ?? 0),
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    /** 束の全カード（絞り込み・出題順は画面側）。各カードに自分の回答状況を付ける */
    public function items(Request $request, CardDeck $deck): JsonResponse
    {
        $items = $deck->items()->get();
        $stats = $this->stats($request->user()->id, $items->pluck('id')->all());

        return response()->json(['data' => $items->map(fn (CardItem $i) => $this->format($i, $stats[$i->id] ?? null))->values()]);
    }

    /** 問題・解答の画像（PDF から切り出した JPEG。storage/app/private/cards/ 以下） */
    public function image(CardItem $item, string $kind): BinaryFileResponse
    {
        $col = ['problem' => 'problem_image', 'answer' => 'answer_image'][$kind] ?? null;
        abort_unless($col !== null && $item->{$col}, 404);
        $path = storage_path('app/private/cards/'.$item->{$col});
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** 回答を記録する（復習状態も進める） */
    public function attempt(Request $request, CardItem $item): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', 'max:20'],
            'isCorrect' => ['required', 'boolean'],
            'isUnsure' => ['sometimes', 'boolean'],
            'answer' => ['nullable', 'string', 'max:2000'],
            'elapsedMs' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'sessionId' => ['nullable', 'integer'],
        ]);
        $userId = $request->user()->id;
        $isCorrect = (bool) $data['isCorrect'];
        $isUnsure = $isCorrect && ! empty($data['isUnsure']);
        [$prev] = $this->review->applyResult($userId, ReviewState::KIND_CARD, $item->id, $isCorrect, $isUnsure);
        $sessionId = ! empty($data['sessionId']) && QuizSession::where('id', $data['sessionId'])->where('user_id', $userId)->exists() ? (int) $data['sessionId'] : null;
        $attempt = CardAttempt::create([
            'user_id' => $userId,
            'quiz_session_id' => $sessionId,
            'card_item_id' => $item->id,
            'mode' => $data['mode'],
            'is_correct' => $isCorrect,
            'is_unsure' => $isUnsure,
            'answer' => $data['answer'] ?? null,
            'answered_at' => now(),
            'elapsed_ms' => $data['elapsedMs'] ?? null,
            'review_prev' => ['state' => $prev],
        ]);

        return response()->json(['data' => [
            'attemptId' => $attempt->id,
            'stat' => $this->stats($userId, [$item->id])[$item->id] ?? null,
        ]]);
    }

    /** 回答の正誤（自己判定）・「あいまい」を付け替える。不正解にしたら「あいまい」は外れる */
    public function updateAttempt(Request $request, CardAttempt $attempt): JsonResponse
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
        $prev = $attempt->review_prev;
        $this->review->reapply($userId, ReviewState::KIND_CARD, $attempt->card_item_id, $prev['state'] ?? null, is_array($prev), $isCorrect, $isCorrect && $isUnsure);

        return response()->json(['data' => [
            'attemptId' => $attempt->id,
            'stat' => $this->stats($userId, [$attempt->card_item_id])[$attempt->card_item_id] ?? null,
        ]]);
    }

    /**
     * チェックシート用: 束のカードごとの日ごとの結果。
     * 同じ日に何度か解いたときは 1 日 1 つにまとめ、1 度でも不正解なら 0（赤）、正解だけであいまいがあれば 2（青）、それ以外 1（黒）。
     * 返り値: { marks: { カードの no: [[YYYY-MM-DD, 0|1|2], ...] } }
     */
    public function checksheet(Request $request, CardDeck $deck): JsonResponse
    {
        $userId = $request->user()->id;
        $tz = max(-840, min(840, (int) $request->query('tz', 540)));
        // 日ごとの集計は PHP 側で行う（MySQL と SQLite の両方で動くように）
        $rows = CardAttempt::query()
            ->join('card_items', 'card_items.id', '=', 'card_attempts.card_item_id')
            ->where('card_attempts.user_id', $userId)
            ->where('card_items.card_deck_id', $deck->id)
            ->orderBy('card_items.no')->orderBy('card_attempts.answered_at')
            ->get(['card_items.no AS no', 'card_attempts.answered_at AS at', 'card_attempts.is_correct AS ok', 'card_attempts.is_unsure AS unsure']);
        $days = [];
        foreach ($rows as $r) {
            $d = \Carbon\Carbon::parse($r->at)->addMinutes($tz)->toDateString();
            $k = $r->no.'|'.$d;
            $cur = $days[$k] ?? ['no' => (int) $r->no, 'd' => $d, 'ok' => 1, 'unsure' => 0];
            $cur['ok'] = min($cur['ok'], (int) $r->ok);
            $cur['unsure'] = max($cur['unsure'], (int) $r->unsure);
            $days[$k] = $cur;
        }
        $marks = [];
        foreach ($days as $r) {
            $marks[$r['no']][] = [$r['d'], $r['ok'] === 0 ? 0 : ($r['unsure'] ? 2 : 1)];
        }

        return response()->json(['data' => ['marks' => (object) $marks]]);
    }

    /**
     * カードごとの回答状況。
     * @return array<int, array>
     */
    private function stats(int $userId, array $itemIds): array
    {
        if (! $itemIds) {
            return [];
        }
        $rows = CardAttempt::query()
            ->where('user_id', $userId)
            ->whereIn('card_item_id', $itemIds)
            ->selectRaw('card_item_id AS iid, SUM(is_correct) AS c, COUNT(*) AS n, MAX(id) AS last_id')
            ->groupBy('card_item_id')
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $last = CardAttempt::query()->whereIn('id', $rows->pluck('last_id'))->get()->keyBy('card_item_id');
        $reviews = $this->review->payloads($userId, ReviewState::KIND_CARD, $rows->pluck('iid')->map(fn ($v) => (int) $v)->all());

        $out = [];
        foreach ($rows as $r) {
            $l = $last[$r->iid] ?? null;
            $out[(int) $r->iid] = [
                'correct' => (int) $r->c,
                'incorrect' => (int) $r->n - (int) $r->c,
                'last' => $l ? (bool) $l->is_correct : null,
                'lastUnsure' => $l ? (bool) $l->is_unsure : false,
                'lastAt' => $l?->answered_at?->toDateTimeString(),
                'lastMode' => $l?->mode,
                'review' => $reviews[(int) $r->iid] ?? null,
            ];
        }

        return $out;
    }

    private function format(CardItem $i, ?array $stat): array
    {
        return [
            'id' => $i->id,
            'deckId' => $i->card_deck_id,
            'no' => $i->no,
            'chapterNo' => $i->chapter_no,
            'chapter' => $i->chapter,
            'section' => $i->section,
            'kind' => $i->kind,
            'front' => $i->front,
            'back' => $i->back,
            'extra' => $i->extra ?: null,
            'page' => $i->page,
            'bookPage' => $i->book_page,
            'hasProblemImage' => $i->problem_image !== null,
            'hasAnswerImage' => $i->answer_image !== null,
            'stat' => $stat,
        ];
    }
}
