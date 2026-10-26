<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 英文法・語法の問題に、教材の解説ページの文章（詳細解説。PDF を OCR で読み取ったもの）を持たせる。
 * 一覧 API には含めず、解説モーダルの「詳細解説」で 1 問ずつ取得する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grammar_questions', function (Blueprint $table) {
            $table->text('explanation')->nullable()->after('note');
            $table->unsignedSmallInteger('explanation_page')->nullable()->after('explanation'); // 元 PDF のページ番号（参照用）
        });
    }

    public function down(): void
    {
        Schema::table('grammar_questions', function (Blueprint $table) {
            $table->dropColumn(['explanation', 'explanation_page']);
        });
    }
};
