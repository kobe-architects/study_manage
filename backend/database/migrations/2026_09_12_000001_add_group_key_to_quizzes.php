<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 小テストのグループ（箱）対応。
 * 1回の出題で複数の教材（＋英単語テスト）から出題した場合、教材ごとに quizzes 行を分けて
 * 作成し（PDF・提出・採点は教材ごと）、同じ group_key で「1つの小テスト」としてまとめて表示する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->string('group_key', 40)->nullable()->after('created_by')->index();
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropIndex(['group_key']);
            $table->dropColumn('group_key');
        });
    }
};
