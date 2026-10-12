<?php

use App\Support\GanttSubjectGuess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ガントチャートの行に科目（subjects）を紐づけ、行の名前を科目のイメージカラーで表示できるようにする。
 * 既存の行は名前（「数学A」→ 数学 など）から科目を推定して埋める。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gantt_rows', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->after('group_name')->constrained('subjects')->nullOnDelete();
        });

        $rows = DB::table('gantt_rows')
            ->join('gantt_charts', 'gantt_charts.id', '=', 'gantt_rows.gantt_chart_id')
            ->select('gantt_rows.id', 'gantt_rows.title', 'gantt_rows.group_name', 'gantt_charts.user_id')
            ->get();
        foreach ($rows as $r) {
            $sid = GanttSubjectGuess::guess((int) $r->user_id, (string) $r->title, $r->group_name);
            if ($sid !== null) {
                DB::table('gantt_rows')->where('id', $r->id)->update(['subject_id' => $sid]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('gantt_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
        });
    }
};
