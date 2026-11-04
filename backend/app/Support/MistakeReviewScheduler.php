<?php

namespace App\Support;

use App\Models\MistakeNote;
use Carbon\CarbonInterface;

/**
 * 間違いノート 問題解答モードの間隔反復（忘却曲線に合わせた次回の出題時刻）。
 * 評価は 4 段階: again（もう一度）< hard（難しい）< good（正解）< easy（簡単）。左ほど定着していない。
 *
 * - もう一度: 間隔 0・10 分後に再出題（同じ回の最後にもう一度出る）。係数を下げ、連続回数をリセット。
 * - 難しい: 間隔を少しだけ延ばす（×1.2、最低 +1 日）。係数を少し下げる。
 * - 正解: 間隔 × 係数（初回は 1 日）。
 * - 簡単: 間隔 × 係数 × 1.3（初回は 4 日）。係数を上げる。
 * 係数は 1.3〜3.0、間隔は最長 365 日。
 */
class MistakeReviewScheduler
{
    public const AGAIN_MINUTES = 10;

    private const MAX_INTERVAL = 365;

    /**
     * 評価後の状態を計算する（保存はしない）。
     *
     * @return array{interval: float, ease: float, reps: int, lapses: int, due_at: CarbonInterface}
     */
    public static function next(MistakeNote $note, string $grade, CarbonInterface $now): array
    {
        $interval = (float) $note->review_interval;
        $ease = (float) ($note->review_ease ?: 2.5);
        $reps = (int) $note->review_reps;
        $lapses = (int) $note->review_lapses;

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

    /** 各評価を選んだときの次回までの間隔（ボタンの下に表示する短い文字） */
    public static function preview(MistakeNote $note, CarbonInterface $now): array
    {
        $out = [];
        foreach (['again', 'hard', 'good', 'easy'] as $g) {
            $out[$g] = self::label(self::next($note, $g, $now)['interval']);
        }

        return $out;
    }

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
