<?php

namespace App\Support;

use App\Models\Subject;

/**
 * ガントチャートの行（科目・学習分野）の名前から科目を推定する。
 * 「数学A」→ 数学、「物理基礎・物理」→ 物理 のように、科目名で始まる／科目名を含む行をその科目にする。
 * 行の名前で決まらないときはグループ名（「数学」など）で判定し、どれにも当たらなければ null。
 */
final class GanttSubjectGuess
{
    /** @var array<int, array<int, array{id: int, name: string}>> ユーザーごとの科目（名前の長い順）のキャッシュ */
    private static array $cache = [];

    public static function guess(int $userId, string $title, ?string $group): ?int
    {
        $subjects = self::subjects($userId);
        $title = trim($title);
        foreach ($subjects as $s) {
            if (str_starts_with($title, $s['name'])) {
                return $s['id'];
            }
        }
        foreach ($subjects as $s) {
            if (str_contains($title, $s['name'])) {
                return $s['id'];
            }
        }
        // グループ名が科目名そのもの（「数学」など）のときだけ採用する。
        // 「国語・社会・情報」のような複数科目のまとめ名で国語に寄せてしまわないよう、含む判定はしない
        if ($group !== null && trim($group) !== '') {
            foreach ($subjects as $s) {
                if (trim($group) === $s['name']) {
                    return $s['id'];
                }
            }
        }

        return null;
    }

    /** @return array<int, array{id: int, name: string}> */
    private static function subjects(int $userId): array
    {
        if (! isset(self::$cache[$userId])) {
            $list = Subject::where('user_id', $userId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->filter(fn (Subject $s) => trim((string) $s->name) !== '')
                ->map(fn (Subject $s) => ['id' => (int) $s->id, 'name' => trim((string) $s->name)])
                ->values()
                ->all();
            // 長い名前を先に見る（「数学Ⅱ」と「数学」のような包含関係に備える）
            usort($list, fn ($a, $b) => mb_strlen($b['name']) <=> mb_strlen($a['name']));
            self::$cache[$userId] = $list;
        }

        return self::$cache[$userId];
    }
}
