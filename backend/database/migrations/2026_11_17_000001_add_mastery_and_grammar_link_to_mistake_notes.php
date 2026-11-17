<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 間違いノート:
 * - mastery: 手動で付ける定着度（mastered=定着 / unsure=あいまい / unmastered=未定着 / NULL=未設定）。閲覧・出題で絞り込む
 * - grammar_question_id: スクランブル（英文法・語法）の問題から自動で作ったページの元の問題（同じ問題から 2 度作らないため）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mistake_notes', function (Blueprint $table) {
            $table->string('mastery', 12)->nullable()->after('hidden');
            $table->foreignId('grammar_question_id')->nullable()->after('mistake_deck_id')->constrained('grammar_questions')->nullOnDelete();
            $table->index(['user_id', 'grammar_question_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mistake_notes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'grammar_question_id']);
            $table->dropConstrainedForeignId('grammar_question_id');
            $table->dropColumn('mastery');
        });
    }
};
