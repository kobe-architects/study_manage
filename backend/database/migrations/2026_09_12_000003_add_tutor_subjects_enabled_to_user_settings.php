<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 講師メニューに「科目別学習状況」を表示するかどうかの設定（生徒側で切替）。
 * 既定は表示（true・従来どおり）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('tutor_subjects_enabled')->default(true)->after('tutor_quiz_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('tutor_subjects_enabled');
        });
    }
};
