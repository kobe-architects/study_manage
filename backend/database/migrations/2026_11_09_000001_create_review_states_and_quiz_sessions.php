<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 忘却曲線（間隔反復）による復習と、1 回の出題（セッション）の記録。
 * - review_states: 生徒 × 問題（kind = grammar / vocab / card）ごとの復習状態（間隔・係数・次回の期限）。
 *   間違いノートの mistake_notes.review_* と同じ考え方を、スクランブル・英単語・学習カードで共通に使う。
 *   評価は回答から自動で決める（不正解=もう一度 / 正解だがあいまい=難しい / 正解=正解）。
 * - quiz_sessions: 1 回の出題（開始〜終了）。問題数・正解数・あいまい数・合計解答時間を記録し、1 問あたりの解答時間を出す。
 * - 回答履歴（grammar_attempts / vocabulary_attempts）に、所属するセッションと解答までの時間（ミリ秒）、
 *   復習状態の変更前の値（あいまい・正誤の付け替えで復習状態をやり直すため）を持たせる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // grammar / vocab / card
            $table->unsignedBigInteger('item_id'); // grammar_questions.id / vocabularies.id / card_items.id
            $table->decimal('interval', 8, 2)->default(0); // 日数（0 = 未学習 or もう一度）
            $table->decimal('ease', 4, 2)->default(2.50);
            $table->unsignedInteger('reps')->default(0); // 続けて覚えている回数
            $table->unsignedInteger('lapses')->default(0); // 「もう一度」の回数
            $table->timestamp('due_at')->nullable(); // 次に出題する時刻
            $table->timestamp('last_at')->nullable();
            $table->string('last_grade', 10)->nullable(); // again / hard / good / easy
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'item_id']);
            $table->index(['user_id', 'kind', 'due_at']);
        });

        Schema::create('quiz_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // grammar / vocab / card
            $table->unsignedBigInteger('ref_id')->nullable(); // vocab: study_resources.id / card: card_decks.id
            $table->string('ref_key', 40)->nullable(); // grammar: 問題集キー（grammar / usage …）
            $table->string('mode', 30)->nullable(); // 出題形式（choice / input / translate / fill / review …）
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('correct')->default(0);
            $table->unsignedInteger('unsure')->default(0);
            $table->unsignedBigInteger('elapsed_ms')->default(0); // 解答時間の合計（ミリ秒）
            $table->timestamps();
            $table->index(['user_id', 'kind', 'started_at']);
        });

        foreach (['grammar_attempts', 'vocabulary_attempts'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->foreignId('quiz_session_id')->nullable()->after('user_id')->constrained('quiz_sessions')->nullOnDelete();
                $table->unsignedInteger('elapsed_ms')->nullable()->after('answered_at'); // 問題を見てから解答するまで
                $table->json('review_prev')->nullable()->after('elapsed_ms'); // この回答で更新する前の復習状態
            });
        }
    }

    public function down(): void
    {
        foreach (['grammar_attempts', 'vocabulary_attempts'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropConstrainedForeignId('quiz_session_id');
                $table->dropColumn(['elapsed_ms', 'review_prev']);
            });
        }
        Schema::dropIfExists('quiz_sessions');
        Schema::dropIfExists('review_states');
    }
};
