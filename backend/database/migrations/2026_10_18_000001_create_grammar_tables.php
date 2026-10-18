<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 英文法・語法の問題演習（スクランブル英文法・語法）。
 * grammar_questions = 問題（全ユーザー共通の教材データ。php artisan grammar:import で登録）
 * grammar_attempts  = 生徒ごとの回答履歴（1 回答 = 1 行。最新の行がその問題の現在の状態）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grammar_questions', function (Blueprint $table) {
            $table->id();
            $table->string('book', 20); // grammar=英文法 / usage=語法
            $table->unsignedSmallInteger('no'); // 通し番号（英文法 1〜473・語法 474〜725）
            $table->unsignedTinyInteger('chapter_no');
            $table->string('chapter', 100); // 第1章 時制
            $table->unsignedSmallInteger('theme'); // 教材の Theme 番号
            $table->string('format', 10); // choice=選択 / error=誤り指摘 / order=整序 / fill=空所補充
            $table->string('format_label', 20); // 教材上の形式名（選択・不適切選択・語句整序 など）
            $table->string('importance', 20)->nullable(); // 重要度（語法のみ: 基本・頻出 など）
            $table->text('instruction')->nullable(); // 日本文・指示
            $table->text('question'); // 問題文（英文）
            $table->json('choices')->nullable(); // 選択肢 / 誤り指摘の下線部 / 整序の語句
            $table->unsignedTinyInteger('answer_index')->nullable(); // choice・error の正解（0 始まり）
            $table->text('answer_text')->nullable(); // 正解の語句・訂正・完成文
            $table->json('answers')->nullable(); // 採点用: fill は空所ごとの正答候補、order は完成部分
            $table->string('answer_note', 100)->nullable(); // 整序の注記（〜が不要 / 〜が不足）
            $table->json('missing')->nullable(); // 整序で不足している語
            $table->json('unneeded')->nullable(); // 整序で不要な語
            $table->text('translation')->nullable(); // 和訳
            $table->string('source', 100)->nullable(); // 出典（大学名）
            $table->text('note')->nullable(); // 備考
            $table->timestamps();
            $table->unique(['book', 'no']);
            $table->index(['book', 'chapter_no']);
        });

        Schema::create('grammar_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grammar_question_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_correct');
            $table->text('answer')->nullable(); // 生徒の回答（表示用）
            $table->timestamp('answered_at');
            $table->timestamps();
            $table->index(['user_id', 'grammar_question_id']);
            $table->index(['user_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grammar_attempts');
        Schema::dropIfExists('grammar_questions');
    }
};
