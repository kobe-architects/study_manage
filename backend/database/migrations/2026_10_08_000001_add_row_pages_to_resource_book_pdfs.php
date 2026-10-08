<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 教材の行（例題）→ PDF のページの対応表。番号＝ページの対応（page_map=seq）が使えない教材
 * （スタディサプリの PART 構成など）で「一覧から選ぶ」を使えるようにする。{"<行ID>": [ページ, ...]}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_book_pdfs', function (Blueprint $table) {
            $table->json('row_pages')->nullable()->after('page_links');
        });
    }

    public function down(): void
    {
        Schema::table('resource_book_pdfs', function (Blueprint $table) {
            $table->dropColumn('row_pages');
        });
    }
};
