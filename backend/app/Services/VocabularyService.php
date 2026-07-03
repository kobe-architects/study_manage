<?php

namespace App\Services;

use App\Models\StudyResource;
use App\Models\Vocabulary;
use App\Models\VocabularyAttempt;
use App\Models\VocabularyLearningStat;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 英単語クイズのドメインロジック。
 * 出題選定（SM-2 配分）/ 4択生成 / 回答記録（SM-2 更新）/ 進捗統計。
 */
class VocabularyService
{
    /** SM-2 配分（ハードコード）: 復習60% / 新規30% / 苦手10% */
    private const DUE_RATIO = 0.6;

    private const NEW_RATIO = 0.3;

    /**
     * 出題単語を選定する。
     *
     * @return Collection<int, Vocabulary>
     */
    public function getQuizWords(StudyResource $resource, int $userId, array $params): Collection
    {
        $count = (int) ($params['count'] ?? 20);
        $sectionIds = $params['sectionIds'] ?? null;
        $importances = $params['importances'] ?? null;
        $labels = $params['labels'] ?? null;
        $ordered = (bool) ($params['ordered'] ?? false);
        $vocabularyIds = $params['vocabularyIds'] ?? null;

        $base = Vocabulary::query()
            ->whereHas('section', fn ($q) => $q->where('study_resource_id', $resource->id))
            ->with(['userStat' => fn ($q) => $q->where('user_id', $userId)]);

        // 1. 特定単語指定（リトライ/復習）
        if (! empty($vocabularyIds)) {
            return $base->whereIn('id', $vocabularyIds)->get()->shuffle()->values();
        }

        // 2. 絞り込み
        if (! empty($sectionIds)) {
            $base->whereIn('study_resource_section_id', $sectionIds);
        }
        if (! empty($importances)) {
            $base->whereIn('importance', $importances);
        }
        if (! empty($labels)) {
            $base->whereIn('label', $labels);
        }

        $pool = $base->get();
        if ($pool->isEmpty()) {
            return collect();
        }

        // 3. 順番通り
        if ($ordered) {
            $sorted = $pool->sortBy('sort_order')->values();

            return $count > 0 ? $sorted->take($count)->values() : $sorted;
        }

        // 4. 全件
        if ($count <= 0) {
            return $pool->shuffle()->values();
        }

        // 5. 間隔反復配分
        return $this->allocateBySpacedRepetition($pool, $count, $userId);
    }

    /**
     * @param  Collection<int, Vocabulary>  $pool
     * @return Collection<int, Vocabulary>
     */
    private function allocateBySpacedRepetition(Collection $pool, int $count, int $userId): Collection
    {
        $now = Carbon::now();

        $statOf = fn (Vocabulary $v) => $v->userStat;

        // 復習対象: next_review_at <= now（昇順）
        $due = $pool->filter(function (Vocabulary $v) use ($statOf, $now) {
            $s = $statOf($v);

            return $s && $s->next_review_at && $s->next_review_at->lte($now);
        })->sortBy(fn (Vocabulary $v) => $statOf($v)->next_review_at)->values();

        // 新規: 統計なし（シャッフル）
        $new = $pool->filter(fn (Vocabulary $v) => $statOf($v) === null)->shuffle()->values();

        // 苦手: next_review_at > now（ease_factor 昇順）
        $hard = $pool->filter(function (Vocabulary $v) use ($statOf, $now) {
            $s = $statOf($v);

            return $s && $s->next_review_at && $s->next_review_at->gt($now);
        })->sortBy(fn (Vocabulary $v) => (float) $statOf($v)->ease_factor)->values();

        $dueTake = (int) ceil($count * self::DUE_RATIO);
        $newTake = (int) ceil($count * self::NEW_RATIO);
        $hardTake = max(0, $count - $dueTake - $newTake);

        $selected = collect()
            ->merge($due->take($dueTake))
            ->merge($new->take($newTake))
            ->merge($hard->take($hardTake));

        // 不足分は母集合の残りから補充
        if ($selected->count() < $count) {
            $chosenIds = $selected->pluck('id')->all();
            $rest = $pool->whereNotIn('id', $chosenIds)->shuffle();
            $selected = $selected->merge($rest->take($count - $selected->count()));
        }

        return $selected->unique('id')->take($count)->shuffle()->values();
    }

    /**
     * 4択の選択肢を生成する（同一品詞優先）。
     *
     * @return array<int, array{meaning: string, isCorrect: bool}>
     */
    public function generateChoices(Vocabulary $word): array
    {
        $correct = $word->meaning;

        $samePos = Vocabulary::query()
            ->whereHas('section', fn ($q) => $q->where(
                'study_resource_id',
                $word->section->study_resource_id
            ))
            ->where('id', '!=', $word->id)
            ->where('part_of_speech', $word->part_of_speech)
            ->where('meaning', '!=', $correct)
            ->pluck('meaning')
            ->unique()
            ->values();

        $dummies = $samePos->shuffle()->take(3)->values();

        if ($dummies->count() < 3) {
            $others = Vocabulary::query()
                ->whereHas('section', fn ($q) => $q->where(
                    'study_resource_id',
                    $word->section->study_resource_id
                ))
                ->where('id', '!=', $word->id)
                ->where('meaning', '!=', $correct)
                ->pluck('meaning')
                ->unique()
                ->reject(fn ($m) => $dummies->contains($m))
                ->shuffle()
                ->values();
            $dummies = $dummies->merge($others->take(3 - $dummies->count()));
        }

        $choices = collect([['meaning' => $correct, 'isCorrect' => true]]);
        foreach ($dummies->take(3) as $m) {
            $choices->push(['meaning' => $m, 'isCorrect' => false]);
        }

        return $choices->shuffle()->values()->all();
    }

    /**
     * 回答を記録し、SM-2 で学習統計を更新する。
     */
    public function recordAttempt(Vocabulary $word, int $userId, bool $isCorrect, string $quizType): VocabularyAttempt
    {
        $attempt = VocabularyAttempt::create([
            'vocabulary_id' => $word->id,
            'user_id' => $userId,
            'is_correct' => $isCorrect,
            'quiz_type' => $quizType,
            'answered_at' => Carbon::now(),
        ]);

        $stat = VocabularyLearningStat::firstOrCreate(
            ['vocabulary_id' => $word->id, 'user_id' => $userId],
            ['ease_factor' => 2.50, 'interval_days' => 0, 'repetition_count' => 0]
        );

        $this->applySM2($stat, $isCorrect);

        return $attempt;
    }

    private function applySM2(VocabularyLearningStat $stat, bool $isCorrect): void
    {
        $ease = (float) $stat->ease_factor;

        if ($isCorrect) {
            $stat->correct_count++;
            $stat->repetition_count++;
            if ($stat->repetition_count == 1) {
                $stat->interval_days = 1;
            } elseif ($stat->repetition_count == 2) {
                $stat->interval_days = 6;
            } else {
                $stat->interval_days = (int) round($stat->interval_days * $ease);
            }
            $stat->ease_factor = min(3.0, $ease + 0.1);
        } else {
            $stat->incorrect_count++;
            $stat->repetition_count = 0;
            $stat->interval_days = 1;
            $stat->ease_factor = max(1.3, $ease - 0.2);
        }

        $now = Carbon::now();
        $stat->last_attempted_at = $now;
        $stat->next_review_at = $now->copy()->addDays($stat->interval_days);
        $stat->save();
    }

    /**
     * 進捗統計を算出する。
     *
     * @return array<string, mixed>
     */
    public function getProgress(StudyResource $resource, int $userId): array
    {
        $vocabIds = Vocabulary::query()
            ->whereHas('section', fn ($q) => $q->where('study_resource_id', $resource->id))
            ->pluck('id');

        $totalWords = $vocabIds->count();
        if ($totalWords === 0) {
            return [
                'totalWords' => 0, 'masteredCount' => 0, 'learningCount' => 0,
                'newCount' => 0, 'overallAccuracy' => 0, 'dueForReview' => 0,
            ];
        }

        $stats = VocabularyLearningStat::query()
            ->whereIn('vocabulary_id', $vocabIds)
            ->where('user_id', $userId)
            ->get();

        $statCount = $stats->count();
        $mastered = $stats->filter(
            fn ($s) => $s->repetition_count >= 3 && $s->interval_days >= 7
        )->count();
        $due = $stats->filter(
            fn ($s) => $s->next_review_at && $s->next_review_at->lte(Carbon::now())
        )->count();

        $sumCorrect = (int) $stats->sum('correct_count');
        $sumIncorrect = (int) $stats->sum('incorrect_count');
        $totalAnswers = $sumCorrect + $sumIncorrect;
        $accuracy = $totalAnswers > 0
            ? round($sumCorrect / $totalAnswers * 100, 1)
            : 0;

        return [
            'totalWords' => $totalWords,
            'masteredCount' => $mastered,
            'learningCount' => $statCount - $mastered,
            'newCount' => $totalWords - $statCount,
            'overallAccuracy' => $accuracy,
            'dueForReview' => $due,
        ];
    }

    /**
     * トップページ用: 教材（StudyResource）の習得進捗を、全体 + セクション別で返す。
     * 習得済み = repetition_count >= 3 かつ interval_days >= 7（getProgress と同基準）。
     *
     * @return array<string, mixed>
     */
    public function getProgressBySection(StudyResource $resource, int $userId): array
    {
        $sections = $resource->sections()->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
        $sectionIds = $sections->pluck('id');

        // セクション別 総単語数
        $totals = Vocabulary::query()
            ->whereIn('study_resource_section_id', $sectionIds)
            ->selectRaw('study_resource_section_id AS sid, COUNT(*) AS c')
            ->groupBy('study_resource_section_id')
            ->pluck('c', 'sid');

        // セクション別 習得済み数（自ユーザーの学習統計を結合）
        $mastered = DB::table('vocabularies AS v')
            ->join('vocabulary_learning_stats AS s', function ($j) use ($userId) {
                $j->on('s.vocabulary_id', '=', 'v.id')->where('s.user_id', '=', $userId);
            })
            ->whereIn('v.study_resource_section_id', $sectionIds)
            ->where('s.repetition_count', '>=', 3)
            ->where('s.interval_days', '>=', 7)
            ->selectRaw('v.study_resource_section_id AS sid, COUNT(*) AS c')
            ->groupBy('v.study_resource_section_id')
            ->pluck('c', 'sid');

        $sectionData = $sections->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'totalWords' => (int) ($totals[$s->id] ?? 0),
            'masteredCount' => (int) ($mastered[$s->id] ?? 0),
        ])->values();

        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'totalWords' => (int) $totals->sum(),
            'masteredCount' => (int) $mastered->sum(),
            'sections' => $sectionData,
        ];
    }
}
