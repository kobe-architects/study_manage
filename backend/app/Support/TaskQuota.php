<?php

namespace App\Support;

use App\Models\StudyTask;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * タスクの日割り計算（frontend/src/lib/tasks.ts と同じ規則）。
 * 1 日あたりのタスクは登録した量そのまま。1 週あたりのタスクは、その週（月曜始まり）の取り組む曜日の数で割り、
 * 割り切れない端数は後ろの曜日に寄せて週の合計が登録した量にそろうようにする（140 words / 週・毎日 → 20 words）。
 *
 * 画面（トップページ）は従来どおり画面側で計算する。ここはアプリ・ウィジェット向けの API（/tasks/day）で使う。
 */
final class TaskQuota
{
    /** その曜日に取り組むタスクか（weekdays が null・空なら毎日。0=日〜6=土） */
    public static function onWeekday(?array $weekdays, CarbonInterface $d): bool
    {
        if ($weekdays === null || count($weekdays) === 0) {
            return true;
        }

        return in_array($d->dayOfWeek, array_map('intval', $weekdays), true);
    }

    /** 開始日〜終了日の中か */
    public static function inPeriod(StudyTask $task, CarbonInterface $d): bool
    {
        $s = $d->toDateString();
        if ($s < $task->start_on->toDateString()) {
            return false;
        }

        return $task->end_on === null || $s <= $task->end_on->toDateString();
    }

    /** その日に表示するタスクか */
    public static function isActiveOn(StudyTask $task, CarbonInterface $d): bool
    {
        return (bool) $task->is_active && self::onWeekday($task->weekdays, $d) && self::inPeriod($task, $d);
    }

    /** 週の始まり（月曜） */
    public static function weekStart(CarbonInterface $d): CarbonImmutable
    {
        $day = CarbonImmutable::parse($d->toDateString());

        return $day->subDays(($day->dayOfWeek + 6) % 7);
    }

    /** 週の 7 日（月〜日） */
    public static function weekDates(CarbonInterface $start): array
    {
        $s = CarbonImmutable::parse($start->toDateString());

        return array_map(fn (int $i) => $s->addDays($i), range(0, 6));
    }

    /** その日の予定量 */
    public static function quotaOn(StudyTask $task, CarbonInterface $d): int
    {
        if (! self::isActiveOn($task, $d)) {
            return 0;
        }
        if ($task->period === 'day') {
            return (int) $task->amount;
        }
        $days = array_values(array_filter(self::weekDates(self::weekStart($d)), fn ($x) => self::onWeekday($task->weekdays, $x)));
        $n = count($days);
        if ($n === 0) {
            return 0;
        }
        $key = $d->toDateString();
        $i = null;
        foreach ($days as $idx => $x) {
            if ($x->toDateString() === $key) {
                $i = $idx;
                break;
            }
        }
        if ($i === null) {
            return 0;
        }
        $amount = (int) $task->amount;

        return intdiv($amount * ($i + 1), $n) - intdiv($amount * $i, $n);
    }

    /** 「20 words」「1単元」。単位が英字で始まるときだけ間に空白を入れる */
    public static function fmtAmount(int $n, string $unit): string
    {
        $u = trim($unit);
        if ($u === '') {
            return (string) $n;
        }

        return preg_match('/^[A-Za-z]/', $u) ? "{$n} {$u}" : "{$n}{$u}";
    }
}
