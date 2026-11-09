<?php

namespace Tests\Feature;

use App\Models\CardDeck;
use App\Models\CardItem;
use App\Models\GrammarQuestion;
use App\Models\ResourceBook;
use App\Models\ReviewState;
use App\Models\User;
use App\Support\ReviewScheduler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** 忘却曲線の復習状態・出題セッション・学習カードの API */
class ReviewAndCardsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $u = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($u);

        return $u;
    }

    private function question(): GrammarQuestion
    {
        return GrammarQuestion::create([
            'book' => 'grammar', 'no' => 1, 'chapter_no' => 1, 'chapter' => '第1章 時制', 'theme' => 1,
            'format' => 'choice', 'format_label' => '選択', 'question' => 'I ( ) tennis.', 'choices' => ['play', 'plays'], 'answer_index' => 0,
        ]);
    }

    public function test_scheduler_grades_from_result(): void
    {
        $this->assertSame('again', ReviewScheduler::gradeOf(false, false));
        $this->assertSame('hard', ReviewScheduler::gradeOf(true, true));
        $this->assertSame('good', ReviewScheduler::gradeOf(true, false));
        $now = Carbon::parse('2026-11-09 10:00:00');
        $n = ReviewScheduler::next(null, 'good', $now);
        $this->assertSame(1.0, $n['interval']);
        $this->assertSame('2026-11-10', $n['due_at']->toDateString());
        $n2 = ReviewScheduler::next($n, 'good', $now);
        $this->assertGreaterThan(1, $n2['interval']);
        $a = ReviewScheduler::next($n2, 'again', $now);
        $this->assertSame(0.0, $a['interval']);
        $this->assertSame(1, $a['lapses']);
    }

    public function test_grammar_attempt_updates_review_state_and_session(): void
    {
        $u = $this->owner();
        $q = $this->question();
        $s = $this->postJson('/api/quiz-sessions', ['kind' => 'grammar', 'refKey' => 'grammar'])->assertCreated()->json('data');

        $r = $this->postJson("/api/grammar/questions/{$q->id}/attempt", ['isCorrect' => true, 'answer' => 'play', 'elapsedMs' => 4200, 'sessionId' => $s['id']])
            ->assertOk()->json('data');
        $this->assertEquals(1, $r['stat']['review']['interval']);
        $this->assertFalse($r['stat']['review']['isDue']);
        $state = ReviewState::where(['user_id' => $u->id, 'kind' => 'grammar', 'item_id' => $q->id])->first();
        $this->assertNotNull($state);
        $this->assertSame('good', $state->last_grade);

        // あいまいを付けたら「難しい」で計算し直す（前の状態＝未学習からやり直すので lapses は増えず、間隔は 1 日のまま）
        $this->putJson("/api/grammar/attempts/{$r['attemptId']}", ['isUnsure' => true])->assertOk();
        $state = ReviewState::where(['user_id' => $u->id, 'kind' => 'grammar', 'item_id' => $q->id])->firstOrFail();
        $this->assertSame('hard', $state->last_grade);
        $this->assertSame(0, $state->lapses);

        // 不正解に変えたら「もう一度」になり、期限は 10 分後（= 今日の復習対象ではないがすぐ期限になる）
        $this->putJson("/api/grammar/attempts/{$r['attemptId']}", ['isCorrect' => false])->assertOk();
        $state = ReviewState::where(['user_id' => $u->id, 'kind' => 'grammar', 'item_id' => $q->id])->firstOrFail();
        $this->assertSame('again', $state->last_grade);
        $this->assertSame(1, $state->lapses);

        $f = $this->postJson("/api/quiz-sessions/{$s['id']}/finish")->assertOk()->json('data');
        $this->assertSame(1, $f['total']);
        $this->assertSame(0, $f['correct']);
        $this->assertSame(4200, $f['elapsedMs']);
        $this->assertSame(4200, $f['avgMs']);

        $books = $this->getJson('/api/grammar/books')->assertOk()->json('data');
        $this->assertSame(0, collect($books)->firstWhere('key', 'grammar')['due']); // 10 分後なのでまだ期限ではない
        $list = $this->getJson('/api/quiz-sessions?kind=grammar&refKey=grammar')->assertOk()->json('data');
        $this->assertCount(1, $list);
    }

    public function test_due_count_appears_after_due_time(): void
    {
        $u = $this->owner();
        $q = $this->question();
        $this->postJson("/api/grammar/questions/{$q->id}/attempt", ['isCorrect' => true])->assertOk();
        ReviewState::query()->update(['due_at' => Carbon::now()->subMinute()]);
        $books = $this->getJson('/api/grammar/books')->assertOk()->json('data');
        $this->assertSame(1, collect($books)->firstWhere('key', 'grammar')['due']);
        $qs = $this->getJson('/api/grammar/questions?book=grammar')->assertOk()->json('data');
        $this->assertTrue($qs[0]['stat']['review']['isDue']);
    }

    public function test_cards_api(): void
    {
        $u = $this->owner();
        $deck = CardDeck::create(['key' => 'test_deck', 'subject' => 'english', 'name' => 'テスト', 'student_only' => true]);
        $item = CardItem::create([
            'card_deck_id' => $deck->id, 'no' => 1, 'chapter_no' => 1, 'chapter' => '前期 第1週', 'section' => '1.1',
            'kind' => 'example', 'front' => 'I regret having married so young.', 'back' => '私は若くして結婚したことを後悔しています。',
            'extra' => ['blanks' => [[9, 23]]],
        ]);
        $decks = $this->getJson('/api/cards/decks?subject=english')->assertOk()->json('data');
        $this->assertCount(1, $decks);
        $this->assertSame(1, $decks[0]['unanswered']);

        $items = $this->getJson("/api/cards/decks/{$deck->id}/items")->assertOk()->json('data');
        $this->assertSame([[9, 23]], $items[0]['extra']['blanks']);

        $r = $this->postJson("/api/cards/items/{$item->id}/attempt", ['mode' => 'fill', 'isCorrect' => false, 'answer' => 'having marry', 'elapsedMs' => 3000])
            ->assertOk()->json('data');
        $this->assertFalse($r['stat']['last']);
        $this->assertSame('again', $r['stat']['review']['lastGrade']);

        $this->putJson("/api/cards/attempts/{$r['attemptId']}", ['isCorrect' => true, 'isUnsure' => true])->assertOk()
            ->assertJsonPath('data.stat.review.lastGrade', 'hard');

        $sheet = $this->getJson("/api/cards/decks/{$deck->id}/checksheet?tz=540")->assertOk()->json('data.marks');
        $this->assertSame(2, $sheet['1'][0][1]); // 正解・あいまい → 2（青）

        $decks = $this->getJson('/api/cards/decks?subject=english')->assertOk()->json('data');
        $this->assertSame(1, $decks[0]['correct']);
    }

    public function test_tutor_cannot_see_student_only_books(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $tutor = User::factory()->create(['role' => 'tutor', 'student_id' => $owner->id]);
        $visible = ResourceBook::create(['user_id' => $owner->id, 'type' => '問題集', 'title' => '普通の教材', 'sort_order' => 0]);
        $hidden = ResourceBook::create(['user_id' => $owner->id, 'type' => '教科書', 'title' => '鉄緑会 英文法', 'sort_order' => 0, 'student_only' => true]);
        Sanctum::actingAs($tutor);
        $books = $this->getJson('/api/tutor/quiz-books')->assertOk()->json('data');
        $this->assertSame(['普通の教材'], array_column($books, 'title'));
        $this->getJson("/api/tutor/resource-books/{$hidden->id}/quiz-rows")->assertForbidden();
        $this->getJson("/api/tutor/resource-books/{$visible->id}/quiz-rows")->assertOk();
    }
}
