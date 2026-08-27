<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アカウント種別: owner(生徒・管理者) / tutor(家庭教師)。
 * tutor は student_id で担当生徒(owner)を指し、生徒のデータを閲覧・操作する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 10)->default('owner')->after('password');
            $table->foreignId('student_id')->nullable()->after('role')->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_id');
            $table->dropColumn('role');
        });
    }
};
