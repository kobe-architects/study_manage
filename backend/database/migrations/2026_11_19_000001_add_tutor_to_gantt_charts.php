<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 計画表（ガントチャート）を講師側でも使えるようにする。
 * tutor_id = null が生徒側の計画表、tutor_id = 講師の users.id がその講師の計画表（生徒側の一覧には出ない）。
 * user_id は従来どおり生徒（行の科目の参照先）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gantt_charts', function (Blueprint $table) {
            $table->foreignId('tutor_id')->nullable()->after('user_id')->constrained('users')->cascadeOnDelete();
            $table->index(['user_id', 'tutor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('gantt_charts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'tutor_id']);
            $table->dropConstrainedForeignId('tutor_id');
        });
    }
};
