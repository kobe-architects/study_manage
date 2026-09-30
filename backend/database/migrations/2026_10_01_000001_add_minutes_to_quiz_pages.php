<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 小テストのページごとの時間。
 * guide_minutes: 目安時間（★の数 × 5 分。出題時に教材行の難易度から求める。★が無い行は null）
 * answer_minutes: 生徒が提出時に登録した「回答にかかった時間」（分）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_pages', function (Blueprint $table) {
            $table->unsignedSmallInteger('guide_minutes')->nullable()->after('resource_book_item_id');
            $table->unsignedSmallInteger('answer_minutes')->nullable()->after('answer_uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_pages', function (Blueprint $table) {
            $table->dropColumn(['guide_minutes', 'answer_minutes']);
        });
    }
};
