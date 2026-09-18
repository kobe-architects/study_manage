<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** カレンダーの予定に登録したユーザー（生徒本人または講師）を記録する。1日に複数の予定を登録できるようにする */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('is_mock')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
