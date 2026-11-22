<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 質問事項のメッセージ: edited_at = 本人が本文を編集した日時（表示に「編集済み」を出す） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('line_notified');
        });
    }

    public function down(): void
    {
        Schema::table('question_messages', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
