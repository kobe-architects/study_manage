<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 間違いノートの分野を自由入力から、学習項目データの 大分類（必須）→ 中分類（必須）→ 小分類（任意）の選択に変える。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mistake_notes', function (Blueprint $table) {
            $table->foreignId('major_category_id')->nullable()->after('subject_id')->constrained('major_categories')->nullOnDelete();
            $table->foreignId('mid_category_id')->nullable()->after('major_category_id')->constrained('mid_categories')->nullOnDelete();
            $table->foreignId('study_item_id')->nullable()->after('mid_category_id')->constrained('study_items')->nullOnDelete();
            $table->dropColumn('field');
        });
    }

    public function down(): void
    {
        Schema::table('mistake_notes', function (Blueprint $table) {
            $table->string('field', 100)->nullable()->after('subject_id');
            $table->dropConstrainedForeignId('study_item_id');
            $table->dropConstrainedForeignId('mid_category_id');
            $table->dropConstrainedForeignId('major_category_id');
        });
    }
};
