<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 課題: 家庭教師が個別学習データ（教材の行）を選択して期限を設定する。
 * user_id は課題の対象（生徒）、created_by は作成者（家庭教師）。
 * 達成判定は goals と同じく「課題作成後に学習記録が付いた行」で自動集計する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->text('note')->nullable();
            $table->date('due_on');
            $table->boolean('achieved')->nullable(); // null=未記録 / true=達成 / false=未達成
            $table->timestamps();
            $table->index(['user_id', 'due_on']);
        });

        Schema::create('assignment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_book_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['assignment_id', 'resource_book_item_id']);
            $table->index('resource_book_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_items');
        Schema::dropIfExists('assignments');
    }
};
