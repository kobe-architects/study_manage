<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 間違いノートの出典を、自由入力から個別学習一覧データ（教材）の選択に変える。
 * 旧 source（文字列）は残し、教材が未選択のときの表示に使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mistake_notes', function (Blueprint $table) {
            $table->foreignId('resource_book_id')->nullable()->after('study_item_id')->constrained('resource_books')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mistake_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resource_book_id');
        });
    }
};
