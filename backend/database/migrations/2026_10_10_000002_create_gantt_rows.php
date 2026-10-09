<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ガントチャートを「行（科目・学習分野）」と「区間（範囲学習→復習・演習→過去問 など、1 行に複数）」に分ける。
 * gantt_rows = 行（タイトル・グループ・並び順）、gantt_tasks は行に属する区間／節目になる。
 * 既存の区間は 1 区間 = 1 行として行を作って付け替える。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gantt_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gantt_chart_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('group_name', 100)->nullable(); // 科目のまとまり（数学・英語など）。変わる所に太い区切り線を引く
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['gantt_chart_id', 'sort_order']);
        });

        Schema::table('gantt_tasks', function (Blueprint $table) {
            $table->foreignId('gantt_row_id')->nullable()->after('gantt_chart_id')->constrained('gantt_rows')->cascadeOnDelete();
        });

        $now = now();
        foreach (DB::table('gantt_tasks')->orderBy('gantt_chart_id')->orderBy('sort_order')->orderBy('id')->get() as $task) {
            $rowId = DB::table('gantt_rows')->insertGetId([
                'gantt_chart_id' => $task->gantt_chart_id,
                'title' => $task->title,
                'group_name' => null,
                'sort_order' => $task->sort_order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('gantt_tasks')->where('id', $task->id)->update(['gantt_row_id' => $rowId]);
        }
    }

    public function down(): void
    {
        Schema::table('gantt_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gantt_row_id');
        });
        Schema::dropIfExists('gantt_rows');
    }
};
