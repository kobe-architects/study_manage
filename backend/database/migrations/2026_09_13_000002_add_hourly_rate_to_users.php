<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 講師の時給（円/時）。生徒がシステム設定の家庭教師アカウントで登録し、請求書の締め時にスナップショットされる */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('hourly_rate')->default(0)->after('line_link_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
        });
    }
};
