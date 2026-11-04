<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * タスク管理（生徒）: 科目ごとの「やるべきこと」を 1 日あたり／1 週あたりの量で登録し、
 * 日別・週別の計画に展開してトップページで消化する。
 * study_tasks = タスク（例: 英単語 LEAP basic 140 words / week）、study_task_logs = 日ごとの消化量。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete(); // 科目（色・グループ分け）。null は「その他」
            $table->string('title', 255); // 例: 英単語 LEAP basic
            $table->unsignedInteger('amount'); // 期間あたりの量（例: 140）
            $table->string('unit', 30); // 量の単位（例: words / 単元 / ページ）
            $table->string('period', 10)->default('week'); // day=1 日あたり / week=1 週あたり
            $table->json('weekdays')->nullable(); // 取り組む曜日（0=日〜6=土）。null は毎日
            $table->date('start_on'); // 開始日
            $table->date('end_on')->nullable(); // 終了日（null は無期限）
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true); // 一時停止は false
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'sort_order']);
        });

        Schema::create('study_task_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_task_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('amount'); // その日に消化した量
            $table->timestamps();
            $table->unique(['study_task_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_task_logs');
        Schema::dropIfExists('study_tasks');
    }
};
