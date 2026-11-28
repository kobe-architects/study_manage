<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 小テストの分割提出。
 * - quizzes.status に partial（分割提出中: 一部のページだけ提出済み。全ページ提出で submitted になる）を追加（列は既存の string(12)）。
 * - quiz_pages.submitted_at: そのページが講師へ提出された日時（撮影・アップロードだけで未提出のページは null）。
 *   既存データは、提出済み・採点済みの小テストの回答ありページを小テストの提出日時で埋める。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_pages', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('answer_uploaded_at');
        });

        DB::table('quiz_pages')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_pages.quiz_id')
            ->whereNotNull('quiz_pages.answer_path')
            ->whereIn('quizzes.status', ['submitted', 'graded'])
            ->update(['quiz_pages.submitted_at' => DB::raw('COALESCE(quizzes.submitted_at, quiz_pages.answer_uploaded_at, quizzes.updated_at)')]);
    }

    public function down(): void
    {
        DB::table('quizzes')->where('status', 'partial')->update(['status' => 'assigned']);
        Schema::table('quiz_pages', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
