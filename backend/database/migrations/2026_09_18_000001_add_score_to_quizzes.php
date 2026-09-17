<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 採点をページ別の○△×から「小テスト全体の得点／満点」に変更する。
 * 既存の採点済み小テストはページ得点の合計を得点、ページ満点の合計を満点として引き継ぐ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->unsignedInteger('score')->nullable()->after('max_score_per_page');
            $table->unsignedInteger('max_score')->nullable()->after('score');
        });
        DB::statement("UPDATE quizzes q SET
            score = (SELECT SUM(p.score) FROM quiz_pages p WHERE p.quiz_id = q.id AND p.score IS NOT NULL),
            max_score = (SELECT SUM(COALESCE(p.max_score, q.max_score_per_page)) FROM quiz_pages p WHERE p.quiz_id = q.id)
            WHERE q.status = 'graded'");
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn(['score', 'max_score']);
        });
    }
};
