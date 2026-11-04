<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 間違いノート: デッキ（本棚の「本」。ページを自由にまとめる単位）と、問題解答モードの復習データ。
 * - mistake_decks: ユーザーごとのデッキ（名前・色・並び順）。ページ（mistake_notes）は 1 つのデッキに属する（任意）。
 * - mistake_notes に復習の状態（間隔反復: 間隔・係数・回数・次回・前回の評価）を持たせる。
 * - mistake_reviews: 解答の履歴（もう一度／難しい／正解／簡単）。
 * 科目「その他」は subjects の行を作らず subject_id = NULL で表す（分類なし。デッキ別の参照のみ）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mistake_decks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('color', 20)->nullable(); // 本の背表紙・表紙の色（#rrggbb）
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'sort_order']);
        });

        Schema::table('mistake_notes', function (Blueprint $table) {
            $table->foreignId('mistake_deck_id')->nullable()->after('resource_book_id')->constrained('mistake_decks')->nullOnDelete();
            // 復習（間隔反復）の状態
            $table->decimal('review_interval', 8, 2)->default(0)->after('sort_order'); // 日数（0 = 未学習 or もう一度）
            $table->decimal('review_ease', 4, 2)->default(2.50)->after('review_interval');
            $table->unsignedInteger('review_reps')->default(0)->after('review_ease'); // 続けて覚えている回数
            $table->unsignedInteger('review_lapses')->default(0)->after('review_reps'); // 「もう一度」の回数
            $table->timestamp('review_due_at')->nullable()->after('review_lapses'); // 次に出題する時刻（NULL = 未学習）
            $table->timestamp('review_last_at')->nullable()->after('review_due_at');
            $table->string('review_last_grade', 10)->nullable()->after('review_last_at'); // again / hard / good / easy
            $table->index(['user_id', 'review_due_at']);
        });

        Schema::create('mistake_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mistake_note_id')->constrained('mistake_notes')->cascadeOnDelete();
            $table->string('grade', 10); // again / hard / good / easy
            $table->decimal('interval_after', 8, 2)->default(0); // 評価後の間隔（日）
            $table->decimal('ease_after', 4, 2)->default(2.50);
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->index(['user_id', 'reviewed_at']);
            $table->index(['mistake_note_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mistake_reviews');
        Schema::table('mistake_notes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'review_due_at']);
            $table->dropConstrainedForeignId('mistake_deck_id');
            $table->dropColumn(['review_interval', 'review_ease', 'review_reps', 'review_lapses', 'review_due_at', 'review_last_at', 'review_last_grade']);
        });
        Schema::dropIfExists('mistake_decks');
    }
};
