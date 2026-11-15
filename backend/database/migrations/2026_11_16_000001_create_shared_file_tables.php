<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ファイル共有（生徒と講師で学習データの PDF などを階層フォルダで共有する）。
 * - shared_folders: フォルダ（親子で階層）。user_id は生徒（データの持ち主）。
 * - shared_files: ファイル。local(private) ディスクの shared/{生徒ID}/ 以下に保存。
 *     search_text は検索用の本文（PDF は画面側で pdf.js が抽出した文字列。無ければ名前だけで検索）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('shared_folders')->cascadeOnDelete();
            $table->string('name', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'parent_id']);
        });

        Schema::create('shared_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('shared_folders')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('mime', 120)->nullable();
            $table->string('file_path', 255);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('page_count')->nullable();
            $table->longText('search_text')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_files');
        Schema::dropIfExists('shared_folders');
    }
};
