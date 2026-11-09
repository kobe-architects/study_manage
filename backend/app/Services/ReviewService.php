<?php

namespace App\Services;

use App\Models\ReviewState;
use App\Support\ReviewScheduler;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 忘却曲線（間隔反復）の復習状態（review_states）の読み書き。スクランブル・英単語・学習カードで共通。
 * 回答を記録したら apply() で状態を進め、回答の正誤・あいまいを後から付け替えたら restore()+apply() でやり直す。
 */
class ReviewService
{
    /**
     * 回答の結果で復習状態を進める。返り値は [更新前の状態（未学習なら null）, 更新後の ReviewState]。
     *
     * @return array{0: array|null, 1: ReviewState}
     */
    public function applyResult(int $userId, string $kind, int $itemId, bool $isCorrect, bool $isUnsure): array
    {
        return $this->apply($userId, $kind, $itemId, ReviewScheduler::gradeOf($isCorrect, $isUnsure));
    }

    /**
     * @return array{0: array|null, 1: ReviewState}
     */
    public function apply(int $userId, string $kind, int $itemId, string $grade): array
    {
        $now = Carbon::now();
        $state = ReviewState::firstOrNew(['user_id' => $userId, 'kind' => $kind, 'item_id' => $itemId]);
        $prev = $state->exists ? $state->toState() : null;
        $next = ReviewScheduler::next($prev, $grade, $now);
        $state->fill([
            'interval' => $next['interval'],
            'ease' => $next['ease'],
            'reps' => $next['reps'],
            'lapses' => $next['lapses'],
            'due_at' => $next['due_at'],
            'last_at' => $now,
            'last_grade' => $grade,
        ])->save();

        return [$prev, $state];
    }

    /**
     * 回答の正誤・あいまいを付け替えたときに、その回答の前の状態（review_prev）に戻してから新しい評価を適用する。
     * review_prev が無い（古い回答）ときは、戻さずに適用だけする。
     *
     * @param  array|null  $prev  回答に保存しておいた更新前の状態。null = その回答の前は未学習
     * @param  bool  $hasPrev  回答に review_prev の記録があるか（無ければ戻さない）
     */
    public function reapply(int $userId, string $kind, int $itemId, ?array $prev, bool $hasPrev, bool $isCorrect, bool $isUnsure): ReviewState
    {
        if ($hasPrev) {
            $this->restore($userId, $kind, $itemId, $prev);
        }

        return $this->applyResult($userId, $kind, $itemId, $isCorrect, $isUnsure)[1];
    }

    /** 状態を指定の値に戻す（null なら未学習に戻す＝行を削除） */
    public function restore(int $userId, string $kind, int $itemId, ?array $prev): void
    {
        $q = ReviewState::where(['user_id' => $userId, 'kind' => $kind, 'item_id' => $itemId]);
        if ($prev === null) {
            $q->delete();

            return;
        }
        $state = $q->firstOrNew(['user_id' => $userId, 'kind' => $kind, 'item_id' => $itemId]);
        $state->fill([
            'interval' => $prev['interval'] ?? 0,
            'ease' => $prev['ease'] ?? 2.5,
            'reps' => $prev['reps'] ?? 0,
            'lapses' => $prev['lapses'] ?? 0,
            'due_at' => isset($prev['due_at']) ? Carbon::parse($prev['due_at']) : null,
            'last_at' => isset($prev['last_at']) ? Carbon::parse($prev['last_at']) : null,
            'last_grade' => $prev['last_grade'] ?? null,
        ])->save();
    }

    /**
     * 問題ごとの復習状態（画面用）。item_id → payload
     *
     * @param  int[]|null  $itemIds  null なら kind の全件
     * @return array<int, array>
     */
    public function payloads(int $userId, string $kind, ?array $itemIds = null): array
    {
        $q = ReviewState::where('user_id', $userId)->where('kind', $kind);
        if ($itemIds !== null) {
            if (! $itemIds) {
                return [];
            }
            $q->whereIn('item_id', $itemIds);
        }
        $now = Carbon::now();
        $out = [];
        foreach ($q->get() as $s) {
            $out[(int) $s->item_id] = $this->payload($s, $now);
        }

        return $out;
    }

    /** 期限が来ている問題の ID（期限の古い順） */
    public function dueIds(int $userId, string $kind, ?array $itemIds = null): Collection
    {
        $q = ReviewState::where('user_id', $userId)->where('kind', $kind)->whereNotNull('due_at')->where('due_at', '<=', Carbon::now());
        if ($itemIds !== null) {
            $q->whereIn('item_id', $itemIds);
        }

        return $q->orderBy('due_at')->pluck('item_id')->map(fn ($v) => (int) $v);
    }

    /** 件数のまとめ（due = 期限が来ている / learning = 学習中（状態あり）） */
    public function summary(int $userId, string $kind, ?array $itemIds = null): array
    {
        $q = ReviewState::where('user_id', $userId)->where('kind', $kind);
        if ($itemIds !== null) {
            if (! $itemIds) {
                return ['due' => 0, 'learning' => 0];
            }
            $q->whereIn('item_id', $itemIds);
        }
        $learning = (clone $q)->count();
        $due = (clone $q)->whereNotNull('due_at')->where('due_at', '<=', Carbon::now())->count();

        return ['due' => $due, 'learning' => $learning];
    }

    public function payload(ReviewState $s, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return [
            'interval' => (float) $s->interval,
            'intervalLabel' => ReviewScheduler::label((float) $s->interval),
            'ease' => (float) ($s->ease ?: 2.5),
            'reps' => (int) $s->reps,
            'lapses' => (int) $s->lapses,
            'dueAt' => $s->due_at?->toIso8601String(),
            'lastAt' => $s->last_at?->toIso8601String(),
            'lastGrade' => $s->last_grade,
            'isDue' => $s->due_at !== null && $s->due_at->lte($now),
        ];
    }
}
