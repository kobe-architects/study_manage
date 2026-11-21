<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 質問事項（生徒 ⇄ 講師の質問スレッド。Slack のようにスレッドごとにメッセージをやりとりする）
 * - question_threads: スレッド。user_id は生徒（講師は targetUserId で同じデータを見る）。タイトル・科目・大分類・中分類は任意。
 *   status = open（質問中）/ resolved（解決済み。更新は生徒側）。last_message_at・message_count は一覧の並び・件数用
 * - question_messages: メッセージ。body は装飾付きの文字（[b][s][u][m][color=..] の簡易タグ。画面側で描く）。line_notified は送信時に LINE 通知したか
 * - question_attachments: 添付（kind = image / file）。本体は storage/app/private/questions/{生徒ID}/{スレッドID}/。画像は JPEG 正規化＋サムネイル
 * - question_reads: 既読位置（スレッド × ユーザーで、読んだ最後のメッセージ ID）。未読 = 自分以外のメッセージで ID がこれより大きいもの
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // 生徒
            $table->string('title', 200)->nullable();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->foreignId('major_category_id')->nullable()->constrained('major_categories')->nullOnDelete();
            $table->foreignId('mid_category_id')->nullable()->constrained('mid_categories')->nullOnDelete();
            $table->string('status', 12)->default('open'); // open / resolved
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('question_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('question_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // 書いた人（生徒 or 講師）
            $table->text('body');
            $table->boolean('line_notified')->default(false);
            $table->timestamps();
            $table->index(['thread_id', 'id']);
        });

        Schema::create('question_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('question_messages')->cascadeOnDelete();
            $table->string('kind', 10); // image / file
            $table->string('name', 255);
            $table->string('mime', 120)->nullable();
            $table->string('path', 400);
            $table->string('thumb_path', 400)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
        });

        Schema::create('question_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('question_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['thread_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_reads');
        Schema::dropIfExists('question_attachments');
        Schema::dropIfExists('question_messages');
        Schema::dropIfExists('question_threads');
    }
};
