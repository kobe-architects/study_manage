<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LINE 通知（Messaging API）連携。
 * line_link_code: アプリ画面に表示する連携コード。LINE のトークにこのコードを送ると
 * line_user_id（プッシュ送信先）が紐づく。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('line_user_id', 64)->nullable()->after('student_id')->index();
            $table->string('line_link_code', 16)->nullable()->after('line_user_id')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['line_user_id', 'line_link_code']);
        });
    }
};
