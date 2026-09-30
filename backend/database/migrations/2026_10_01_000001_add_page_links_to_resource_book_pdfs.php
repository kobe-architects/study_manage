<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 出題用 PDF のページ → 解答つき PDF のページの対応表（ページ数が揃っていない教材向け。null なら同じページ番号） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_book_pdfs', function (Blueprint $table) {
            $table->json('page_links')->nullable()->after('page_offset');
        });
    }

    public function down(): void
    {
        Schema::table('resource_book_pdfs', function (Blueprint $table) {
            $table->dropColumn('page_links');
        });
    }
};
