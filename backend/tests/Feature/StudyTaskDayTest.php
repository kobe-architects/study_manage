<?php

namespace Tests\Feature;

use App\Models\StudyTask;
use App\Models\StudyTaskLog;
use App\Models\Subject;
use App\Models\User;
use App\Support\TaskQuota;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** /tasks/day（iOS アプリ・ウィジェット向けの「その日のタスク」）と日割り計算 */
class StudyTaskDayTest extends TestCase
{
    use RefreshDatabase;

    private function task(User $user, array $attrs): StudyTask
    {
        return StudyTask::create([
            'user_id' => $user->id,
            'title' => 'task',
            'amount' => 10,
            'unit' => 'words',
            'period' => 'week',
            'weekdays' => null,
            'start_on' => '2026-01-01',
            'end_on' => null,
            'is_active' => true,
            'sort_order' => 0,
            ...$attrs,
        ]);
    }

    public function test_week_quota_is_split_over_weekdays_with_remainder_at_the_end(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        // 140 words / 週・毎日 → 20 ずつ
        $t = $this->task($user, ['amount' => 140]);
        $monday = CarbonImmutable::parse('2026-11-09'); // 月曜
        foreach (range(0, 6) as $i) {
            $this->assertSame(20, TaskQuota::quotaOn($t, $monday->addDays($i)));
        }

        // 10 / 週・月水金 → 3, 3, 4（端数は後ろに）
        $t2 = $this->task($user, ['amount' => 10, 'weekdays' => [1, 3, 5]]);
        $this->assertSame(3, TaskQuota::quotaOn($t2, $monday));
        $this->assertSame(0, TaskQuota::quotaOn($t2, $monday->addDay()));
        $this->assertSame(3, TaskQuota::quotaOn($t2, $monday->addDays(2)));
        $this->assertSame(4, TaskQuota::quotaOn($t2, $monday->addDays(4)));

        // 1 日あたりは登録した量そのまま。期間外・停止中は 0
        $t3 = $this->task($user, ['amount' => 5, 'period' => 'day', 'start_on' => '2026-11-10', 'end_on' => '2026-11-10']);
        $this->assertSame(0, TaskQuota::quotaOn($t3, $monday));
        $this->assertSame(5, TaskQuota::quotaOn($t3, $monday->addDay()));
        $this->assertSame(0, TaskQuota::quotaOn($t3, $monday->addDays(2)));
        $t4 = $this->task($user, ['amount' => 5, 'period' => 'day', 'is_active' => false]);
        $this->assertSame(0, TaskQuota::quotaOn($t4, $monday));
    }

    public function test_day_endpoint_returns_sorted_rows_with_progress(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $math = Subject::create(['user_id' => $user->id, 'code' => 'math', 'name' => '数学', 'group_name' => '数学', 'color_vivid' => '#112233', 'sort_order' => 1]);
        $eng = Subject::create(['user_id' => $user->id, 'code' => 'eng', 'name' => '英語', 'group_name' => '英語', 'sort_order' => 0]);

        // 登録順は 数学 → その他 → 英語 だが、応答は 科目の並び順（英語 → 数学）→ 科目なし
        $a = $this->task($user, ['title' => 'FocusGold', 'subject_id' => $math->id, 'amount' => 7, 'period' => 'day', 'unit' => '問', 'sort_order' => 0]);
        $b = $this->task($user, ['title' => '面談', 'subject_id' => null, 'amount' => 1, 'period' => 'day', 'unit' => '回', 'start_on' => '2026-11-09', 'end_on' => '2026-11-09', 'sort_order' => 1]);
        $c = $this->task($user, ['title' => 'LEAP', 'subject_id' => $eng->id, 'amount' => 140, 'period' => 'week', 'unit' => 'words', 'sort_order' => 2]);
        $this->task($user, ['title' => '停止中', 'amount' => 3, 'period' => 'day', 'is_active' => false]);
        $this->task($user, ['title' => '明日から', 'amount' => 3, 'period' => 'day', 'start_on' => '2026-11-10']);
        StudyTaskLog::create(['study_task_id' => $a->id, 'date' => '2026-11-09', 'amount' => 7]);
        StudyTaskLog::create(['study_task_id' => $c->id, 'date' => '2026-11-09', 'amount' => 5]);
        StudyTaskLog::create(['study_task_id' => $c->id, 'date' => '2026-11-10', 'amount' => 20]); // 週の別の日

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/tasks/day?date=2026-11-09')->assertOk();
        $d = $res->json('data');

        $this->assertSame('2026-11-09', $d['date']);
        $this->assertSame('2026-11-09', $d['weekStart']);
        $this->assertSame(['LEAP', 'FocusGold', '面談'], array_column($d['tasks'], 'title'));
        $this->assertSame(3, $d['total']);
        $this->assertSame(1, $d['complete']);
        // (5/20 + 1 + 0) / 3 = 41.67 → 42
        $this->assertSame(42, $d['pct']);

        $leap = $d['tasks'][0];
        $this->assertSame(20, $leap['quota']);
        $this->assertSame(5, $leap['done']);
        $this->assertFalse($leap['complete']);
        $this->assertSame(25, $leap['pct']);
        $this->assertSame(140, $leap['weekQuota']);
        $this->assertSame(25, $leap['weekDone']);
        $this->assertSame('20 words', $leap['quotaLabel']);
        $this->assertSame('英語', $leap['subjectName']);

        $fg = $d['tasks'][1];
        $this->assertTrue($fg['complete']);
        $this->assertSame('#112233', $fg['colorVivid']);
        $this->assertSame('7問', $fg['quotaLabel']);
        $this->assertFalse($fg['isOneDay']);

        $this->assertTrue($d['tasks'][2]['isOneDay']);
        $this->assertNull($d['tasks'][2]['subjectName']);
    }

    public function test_day_endpoint_is_scoped_to_the_user(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $other = User::factory()->create(['role' => 'owner']);
        $this->task($other, ['amount' => 3, 'period' => 'day']);

        Sanctum::actingAs($user);
        $d = $this->getJson('/api/tasks/day?date=2026-11-09')->assertOk()->json('data');
        $this->assertSame([], $d['tasks']);
        $this->assertSame(0, $d['pct']);

        $this->getJson('/api/tasks/day?date=not-a-date')->assertStatus(422);
    }
}
