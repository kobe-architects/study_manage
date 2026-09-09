<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 小テスト機能。
 * - resource_book_pdfs: 教材（個別学習データ）に紐づく PDF（複数可）。
 *     page_map=seq のとき 行の番号(seq_no) + page_offset = PDF ページ として自動対応させる。
 * - quizzes: 講師が出題した小テスト（生徒 user_id に属する）。status: assigned / submitted / graded
 * - quiz_pages: 小テストの各ページ（出題元 PDF のページ、対応する教材行、生徒の回答写真、添削注釈、採点）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_book_pdfs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_book_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('file_path', 255);                 // local(private) ディスク上のパス
            $table->unsignedInteger('page_count');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('page_map', 10)->default('none');  // seq: 番号=ページ（オフセット付き） / none: 手動選択
            $table->integer('page_offset')->default(0);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['resource_book_id', 'sort_order']);
        });

        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();                       // 生徒
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();     // 出題した講師
            $table->foreignId('resource_book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 255);
            $table->text('note')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedInteger('max_score_per_page')->default(10);
            $table->string('file_path', 255)->nullable();      // 出題 PDF（選択ページのみ抽出）
            $table->string('status', 12)->default('assigned'); // assigned / submitted / graded
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('quiz_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_no');                // 小テスト内の順番（1始まり）
            $table->foreignId('resource_book_pdf_id')->nullable()->constrained('resource_book_pdfs')->nullOnDelete();
            $table->unsignedInteger('pdf_page');               // 出題元 PDF のページ番号
            $table->foreignId('resource_book_item_id')->nullable()->constrained()->nullOnDelete(); // 対応する教材行（例題）
            $table->string('label', 255)->nullable();          // 表示名（例: 例題 12 整式の整理）
            $table->foreignId('ref_pdf_id')->nullable()->constrained('resource_book_pdfs')->nullOnDelete(); // 解答参照ページ（任意）
            $table->unsignedInteger('ref_page')->nullable();
            $table->string('answer_path', 255)->nullable();    // 生徒の回答写真
            $table->timestamp('answer_uploaded_at')->nullable();
            $table->json('annotations')->nullable();           // 添削注釈（ベクター）
            $table->string('annotated_path', 255)->nullable(); // 注釈を合成した画像
            $table->string('mark', 4)->nullable();             // o / tri / x
            $table->unsignedInteger('score')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['quiz_id', 'page_no']);
            $table->index('resource_book_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_pages');
        Schema::dropIfExists('quizzes');
        Schema::dropIfExists('resource_book_pdfs');
    }
};
