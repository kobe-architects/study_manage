<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ガントチャート: 数年単位の学習計画を複数作って管理する。
 * gantt_charts = チャート（タイトル・表示期間）、gantt_tasks = チャート内の項目（期間つきのバー／1日のマイルストーン）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gantt_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('note')->nullable();
            $table->date('start_on'); // 表示期間の開始
            $table->date('end_on'); // 表示期間の終了
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('gantt_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gantt_chart_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('kind', 20)->default('task'); // task=期間のバー / milestone=1日の節目
            $table->date('start_on');
            $table->date('end_on');
            $table->string('color', 9)->default('#3b50cc');
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            $table->text('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['gantt_chart_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gantt_tasks');
        Schema::dropIfExists('gantt_charts');
    }
};
