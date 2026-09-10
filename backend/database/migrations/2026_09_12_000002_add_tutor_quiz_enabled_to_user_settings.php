<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 講師メニューに「小テスト」を表示するかどうかの設定（生徒側で切替）。
 * 既定は非表示（false）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('tutor_quiz_enabled')->default(false)->after('start_screen');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('tutor_quiz_enabled');
        });
    }
};
