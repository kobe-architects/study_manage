<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * 忘却曲線に合わせた間隔反復（スクランブル・英単語・学習カードで共通）。
 * 間違いノートの MistakeReviewScheduler と同じ規則を、配列の状態に対して適用する。
 *
 * 評価は 4 段階: again（もう一度）< hard（難しい）< good（正解）< easy（簡単）。
 * - もう一度: 間隔 0・10 分後に再出題。係数 −0.2、連続回数リセット、lapses +1
 * - 難しい: 間隔 ×1.2（最低 +1 日）。係数 −0.15
 * - 正解: 間隔 × 係数（初回は 1 日）
 * - 簡単: 間隔 × 係数 × 1.3（初回は 4 日）。係数 +0.15
 * 係数は 1.3〜3.0、間隔は最長 365 日。
 *
 * 回答から評価を決めるときは gradeOf(): 不正解 → again、正解だが「あいまい」 → hard、正解 → good。
 */
class ReviewScheduler
{
    public const AGAIN_MINUTES = 10;

    public const GRADES = ['again', 'hard', 'good', 'easy'];

    private const MAX_INTERVAL = 365;

    /** 回答の結果（正誤・あいまい）から評価を決める */
    public static function gradeOf(bool $isCorrect, bool $isUnsure): string
    {
        if (! $isCorrect) {
            return 'again';
        }

        return $isUnsure ? 'hard' : 'good';
    }

    /**
     * 評価後の状態を計算する（保存はしない）。
     *
     * @param  array{interval?: float, ease?: float, reps?: int, lapses?: int}|null  $state  現在の状態（未学習なら null）
     * @return array{interval: float, ease: float, reps: int, lapses: int, due_at: CarbonInterface}
     */
    public static function next(?array $state, string $grade, CarbonInterface $now): array
    {
        $interval = (float) ($state['interval'] ?? 0);
        $ease = (float) ($state['ease'] ?? 2.5) ?: 2.5;
        $reps = (int) ($state['reps'] ?? 0);
        $lapses = (int) ($state['lapses'] ?? 0);

        switch ($grade) {
            case 'again':
                $interval = 0;
                $ease = max(1.3, $ease - 0.2);
                $reps = 0;
                $lapses++;
                break;
            case 'hard':
                $interval = $interval < 1 ? 1 : max($interval + 1, round($interval * 1.2));
                $ease = max(1.3, $ease - 0.15);
                $reps++;
                break;
            case 'good':
                $interval = $interval < 1 ? 1 : max($interval + 1, round($interval * $ease));
                $reps++;
                break;
            case 'easy':
                $interval = $interval < 1 ? 4 : max($interval + 1, round($interval * $ease * 1.3));
                $ease = min(3.0, $ease + 0.15);
                $reps++;
                break;
            default:
                abort(422, '評価の値が正しくありません。');
        }
        $interval = min(self::MAX_INTERVAL, (float) $interval);
        $due = $interval <= 0 ? $now->copy()->addMinutes(self::AGAIN_MINUTES) : $now->copy()->addDays((int) round($interval));

        return ['interval' => $interval, 'ease' => round($ease, 2), 'reps' => $reps, 'lapses' => $lapses, 'due_at' => $due];
    }

    /** 間隔（日）の短い表示（10分 / 3日 / 2か月 / 1年） */
    public static function label(float $days): string
    {
        if ($days <= 0) {
            return self::AGAIN_MINUTES.'分';
        }
        $d = (int) round($days);
        if ($d < 30) {
            return $d.'日';
        }
        if ($d < 360) {
            return max(1, (int) round($d / 30)).'か月';
        }

        return '1年';
    }
}
