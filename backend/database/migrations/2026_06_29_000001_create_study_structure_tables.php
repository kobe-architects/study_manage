<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 学習範囲マスタ: 科目(subjects) > 大分類(major_categories) > 中分類(mid_categories) > 小分類(study_items)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);            // math / chem / phys / eng
            $table->string('name', 100);           // 数学 / 化学 ...
            $table->string('group_name', 100);     // 数学 / 理科 / 英語
            $table->string('color_soft', 16)->default('#475569');
            $table->string('color_vivid', 16)->default('#475569');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'sort_order']);
        });

        Schema::create('major_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('subject_id');
        });

        Schema::create('mid_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('major_category_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('major_category_id');
        });

        Schema::create('study_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mid_category_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);           // 小分類名
            $table->integer('sort_order')->default(0);
            $table->boolean('included')->default(true); // 進捗集計に含めるか（進捗対象の設定）
            $table->timestamps();
            $table->index('mid_category_id');
        });

        // 個別学習一覧（教材）: 講義 / 問題集 / 教科書 のタイトル付きコレクション
        Schema::create('resource_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);                 // 講義 / 問題集 / 教科書
            $table->string('title', 255);               // 例: Focus Gold 数学I+数学A
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete(); // タブ内の色分け用
            $table->string('image_path', 255)->nullable(); // タイトル画像
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'type', 'sort_order']);
        });

        // 教材の各行（=1問 / 1講義回 / 1項）。学習項目(小分類)に紐づく。
        Schema::create('resource_book_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_item_id')->nullable()->constrained()->nullOnDelete(); // 科目→大→中→小 名から解決
            $table->string('chapter', 255)->nullable();  // 章
            $table->string('seq_no', 50)->nullable();     // 番号（非数値もありうるので文字列）
            $table->string('check_flag', 20)->nullable(); // Check（◯ など、原文のまま）
            $table->string('title', 500)->nullable();     // タイトル（行の見出し）
            $table->string('difficulty', 20)->nullable(); // 難易度（* ** *** ****）
            $table->json('meta')->nullable();             // 種別固有の追加項目
            $table->boolean('included')->default(true);   // 進捗対象（学習項目への紐づけを有効化）
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['resource_book_id', 'sort_order']);
            $table->index('study_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_book_items');
        Schema::dropIfExists('resource_books');
        Schema::dropIfExists('study_items');
        Schema::dropIfExists('mid_categories');
        Schema::dropIfExists('major_categories');
        Schema::dropIfExists('subjects');
    }
};
