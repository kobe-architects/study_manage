<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 回答履歴に「あいまい」（正解だったが自信がない）を持たせる。
 * 英文法・語法（grammar_attempts）と英単語（vocabulary_attempts）の両方。正解のときだけ解答後に生徒が付ける。
 * チェックシート出力で、正解=黒・不正解=赤・あいまい=青の日付として使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grammar_attempts', function (Blueprint $table) {
            $table->boolean('is_unsure')->default(false)->after('is_correct');
        });
        Schema::table('vocabulary_attempts', function (Blueprint $table) {
            $table->boolean('is_unsure')->default(false)->after('is_correct');
        });
    }

    public function down(): void
    {
        Schema::table('grammar_attempts', function (Blueprint $table) {
            $table->dropColumn('is_unsure');
        });
        Schema::table('vocabulary_attempts', function (Blueprint $table) {
            $table->dropColumn('is_unsure');
        });
    }
};
