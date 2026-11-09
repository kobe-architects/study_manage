<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 学習カード（教材から起こした「覚える箇所」「例文」「問題」を 1 枚ずつテストする仕組み）。
 * 英語学習の「鉄緑会 英文法」、数学学習の「合格る確率」「合格る計算」で使う。
 * - card_decks: カードの束（教材 1 冊）。全ユーザー共通。student_only = 生徒側だけに見せる（講師には出さない）
 * - card_items: カード 1 枚。kind = phrase（囲みの中の覚える表現）/ example（例文）/ problem（画像の問題）
 *   front / back が表と裏の文字、extra に空所（空所補充用の語）や意味の区切りなど。画像は storage/app/private/cards/。
 * - card_attempts: 生徒ごとの回答履歴（1 回答 = 1 行。mode = どの形式で解いたか）
 * - resource_books.student_only: 個別学習データの教材を生徒側だけに表示する（講師の小テスト出題から除く）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_decks', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique(); // tetsuryoku_grammar / ukaru_kakuritsu / ukaru_keisan
            $table->string('subject', 10); // english / math
            $table->string('name', 100);
            $table->string('short_name', 40)->nullable(); // タブに出す短い名前
            $table->boolean('student_only')->default(false);
            $table->string('resource_book_title', 255)->nullable(); // 対応する個別学習データの教材名
            $table->json('structure')->nullable(); // 章の一覧 [[章番号, 名前, 先頭 no, 末尾 no], ...] などチェックシート用
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('card_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_deck_id')->constrained('card_decks')->cascadeOnDelete();
            $table->unsignedInteger('no'); // 束の中の通し番号
            $table->unsignedSmallInteger('chapter_no');
            $table->string('chapter', 150); // 前期 第9週 動名詞(1) / Stage1 原理原則編
            $table->string('section', 150)->nullable(); // 1.2 完了形・受動形 / ITEM 5 場合分け
            $table->string('kind', 20); // phrase / example / problem
            $table->text('front'); // 例文の英文 / 表現 / 問題の見出し
            $table->text('back')->nullable(); // 和訳 / 意味 / 解答の見出し
            $table->json('extra')->nullable(); // 空所にする語 [[開始, 終了], ...]・補足
            $table->unsignedSmallInteger('page')->nullable(); // PDF のページ
            $table->string('book_page', 20)->nullable(); // 教材のページ表記
            $table->string('problem_image', 255)->nullable(); // storage/app/private/cards/ 以下
            $table->string('answer_image', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['card_deck_id', 'no']);
            $table->index(['card_deck_id', 'chapter_no']);
        });

        Schema::create('card_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_session_id')->nullable()->constrained('quiz_sessions')->nullOnDelete();
            $table->foreignId('card_item_id')->constrained('card_items')->cascadeOnDelete();
            $table->string('mode', 20); // translate（和訳）/ fill（空所補充）/ ja2en（意味→表現）/ en2ja（表現→意味）/ image（画像の自己判定）
            $table->boolean('is_correct');
            $table->boolean('is_unsure')->default(false);
            $table->text('answer')->nullable();
            $table->timestamp('answered_at');
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->json('review_prev')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'card_item_id']);
            $table->index(['user_id', 'answered_at']);
        });

        Schema::table('resource_books', function (Blueprint $table) {
            $table->boolean('student_only')->default(false)->after('pinned');
        });
    }

    public function down(): void
    {
        Schema::table('resource_books', function (Blueprint $table) {
            $table->dropColumn('student_only');
        });
        Schema::dropIfExists('card_attempts');
        Schema::dropIfExists('card_items');
        Schema::dropIfExists('card_decks');
    }
};
