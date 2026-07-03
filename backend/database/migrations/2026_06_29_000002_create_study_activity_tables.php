<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 学習記録 / 目標 / カレンダー予定 / ユーザー設定
 */
return new class extends Migration {
    public function up(): void
    {
        // 学習記録: 教材の各行(resource_book_item)に学習日を何度でも登録できる。
        // study_item_id / type は集計を簡潔にするため行から非正規化コピーする。
        Schema::create('study_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_book_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10);   // 講義 / 問題集 / 教科書
            $table->date('studied_on');
            $table->timestamps();
            $table->index(['user_id', 'studied_on']);
            $table->index(['study_item_id', 'type']);
            $table->index('resource_book_item_id');
        });

        // 目標設定
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 255);
            $table->string('scope', 150)->default('all');   // 大分類名 or 'all'
            $table->string('range_label', 150)->nullable(); // 表示用ラベル
            $table->date('deadline');
            $table->unsignedInteger('target')->default(10);
            $table->timestamps();
            $table->index('user_id');
        });

        // カレンダー予定（模試など）
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('title', 255);
            $table->timestamps();
            $table->index(['user_id', 'date']);
        });

        // ユーザー設定（1ユーザー1行）
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 100)->default('');
            $table->string('school', 255)->default('');
            $table->date('exam_date')->nullable();
            $table->string('default_type', 10)->default('問題集');
            $table->boolean('reminder')->default(true);
            $table->boolean('weekly_report')->default(true);
            $table->boolean('hide_empty')->default(false);
            $table->string('start_screen', 20)->default('home');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('study_records');
    }
};
