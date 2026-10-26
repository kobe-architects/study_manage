<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スクランブルの全 Part（語い・イディオム・会話表現・発音・アクセント・特別編）を画像で出題するための列。
 * 画像は storage/app/private/grammar/ 以下（Git 管理外。PDF から切り出した JPEG）。
 * - problem_image: 問題の画像（文法・語法は無し＝Excel 由来の文で出題）
 * - answer_image: 解答欄（和訳つき）の画像。ページごとに 1 枚を共有
 * - explanation_image: 解説ページの該当部分の画像
 * - choice_count: 画像出題で選択肢の数が分かるとき（①〜のボタンを出す）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grammar_questions', function (Blueprint $table) {
            $table->string('problem_image', 120)->nullable()->after('explanation_page');
            $table->string('answer_image', 120)->nullable()->after('problem_image');
            $table->string('explanation_image', 120)->nullable()->after('answer_image');
            $table->unsignedTinyInteger('choice_count')->nullable()->after('explanation_image');
        });
    }

    public function down(): void
    {
        Schema::table('grammar_questions', function (Blueprint $table) {
            $table->dropColumn(['problem_image', 'answer_image', 'explanation_image', 'choice_count']);
        });
    }
};
