<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 小テストに「英単語テスト」ページを追加できるようにする。
 * - kind: pdf（教材 PDF のページ） / vocab（英単語テスト。問題用紙は画面側で描画した画像を保存）
 * - vocab_spec: 出題設定（単語帳・セクション・形式）、vocab_words: 出題語と解答（採点画面・解答 PDF 用）
 * - max_score: ページ満点の個別指定（null なら小テストの既定値。英単語テストは出題数）
 * あわせて既存の単語帳名を「鉄壁」に変更する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_pages', function (Blueprint $table) {
            $table->string('kind', 10)->default('pdf')->after('page_no');
            $table->unsignedInteger('pdf_page')->nullable()->change();
            $table->json('vocab_spec')->nullable()->after('label');
            $table->json('vocab_words')->nullable()->after('vocab_spec');
            $table->string('render_path', 255)->nullable()->after('vocab_words');
            $table->string('answer_render_path', 255)->nullable()->after('render_path');
            $table->unsignedInteger('max_score')->nullable()->after('score');
        });

        DB::table('study_resources')->where('name', '大学入試 必修英単語')->update(['name' => '鉄壁']);
    }

    public function down(): void
    {
        Schema::table('quiz_pages', function (Blueprint $table) {
            $table->dropColumn(['kind', 'vocab_spec', 'vocab_words', 'render_path', 'answer_render_path', 'max_score']);
        });
        DB::table('study_resources')->where('name', '鉄壁')->update(['name' => '大学入試 必修英単語']);
    }
};
