<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 小テストに「自己採点」フラグを追加（生徒が提出時に得点・満点を入力して採点済みにできる） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->boolean('self_graded')->default(false)->after('max_score');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('self_graded');
        });
    }
};
