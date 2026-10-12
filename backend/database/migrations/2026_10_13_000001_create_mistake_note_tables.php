<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 間違いノート: 間違えた問題を写真（必須）＋手書き・入力の解説で 1 ページにまとめ、科目・分野ごとに管理する。
 * mistake_notes = 1 問 = 1 ページ（配置やペンの線は layout に JSON で保存）、mistake_note_images = 問題の写真・説明用の画像。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mistake_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->string('field', 100)->nullable(); // 分野（二次関数 など）
            $table->string('title', 255)->nullable(); // 問題の名前（例: 例題12）
            $table->string('source', 255)->nullable(); // 出典（教材名・ページ）
            $table->date('noted_on'); // 間違えた日
            $table->json('layout')->nullable(); // { items: [画像・文字の配置], ink: [ペンの線] }
            $table->boolean('hidden')->default(false); // 非表示（ノート・PDF から外す）
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'sort_order']);
        });

        Schema::create('mistake_note_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mistake_note_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('extra'); // problem=対象の問題の写真 / extra=説明用の画像
            $table->string('path', 255);
            $table->string('thumb_path', 255)->nullable();
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mistake_note_images');
        Schema::dropIfExists('mistake_notes');
    }
};
