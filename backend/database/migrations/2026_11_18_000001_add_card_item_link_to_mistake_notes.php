<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 間違いノート: card_item_id = 学習カード（数学学習の例題。合格る・スタディサプリ）から自動で作ったページの元のカード
 * （同じカードから 2 度作らないため。スクランブルの grammar_question_id と同じ役割）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mistake_notes', function (Blueprint $table) {
            $table->foreignId('card_item_id')->nullable()->after('grammar_question_id')->constrained('card_items')->nullOnDelete();
            $table->index(['user_id', 'card_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mistake_notes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'card_item_id']);
            $table->dropConstrainedForeignId('card_item_id');
        });
    }
};
