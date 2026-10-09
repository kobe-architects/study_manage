<?php

namespace App\Console\Commands;

use App\Models\GanttChart;
use App\Models\GanttTask;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * JSON からガントチャートを登録する（計画のひな形の投入用）。
 *
 *   php artisan gantt:import database/data/gantt_plan_2029.json
 *   php artisan gantt:import database/data/gantt_plan_2029.json --user=student@example.com --replace
 *
 * JSON: { title, note?, startOn, endOn, rows: [ { title, group?, tasks: [ { title, kind?, startOn, endOn?, color?, progress?, note? } ] } ] }
 * --user を省略すると生徒（owner）が 1 人のときだけそのユーザーに登録する。
 * --replace を付けると同じタイトルの既存チャートを削除してから登録する。
 */
class ImportGanttChart extends Command
{
    protected $signature = 'gantt:import {file : JSON ファイルのパス} {--user= : 登録先ユーザーのメールアドレス} {--replace : 同じタイトルの既存チャートを置き換える}';

    protected $description = 'JSON からガントチャート（行・区間）を登録する';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $path = base_path($path);
        }
        if (! is_file($path)) {
            $this->error('ファイルが見つかりません: '.$this->argument('file'));

            return self::FAILURE;
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || empty($json['title']) || empty($json['startOn']) || empty($json['endOn']) || ! is_array($json['rows'] ?? null)) {
            $this->error('JSON の形式が正しくありません（title / startOn / endOn / rows が必要）');

            return self::FAILURE;
        }

        $email = $this->option('user');
        if ($email) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                $this->error('ユーザーが見つかりません: '.$email);

                return self::FAILURE;
            }
        } else {
            $owners = User::where('role', 'owner')->get();
            if ($owners->count() !== 1) {
                $this->error('生徒（owner）が '.$owners->count().' 人います。--user=メールアドレス で指定してください');

                return self::FAILURE;
            }
            $user = $owners->first();
        }

        $chart = DB::transaction(function () use ($json, $user) {
            if ($this->option('replace')) {
                $n = GanttChart::where('user_id', $user->id)->where('title', $json['title'])->delete();
                if ($n) {
                    $this->line("既存のチャート {$n} 件を削除しました");
                }
            }
            $chart = GanttChart::create([
                'user_id' => $user->id,
                'title' => $json['title'],
                'note' => $json['note'] ?? null,
                'start_on' => $json['startOn'],
                'end_on' => $json['endOn'],
            ]);
            $now = now();
            foreach ($json['rows'] as $ri => $row) {
                $r = $chart->rows()->create([
                    'title' => (string) ($row['title'] ?? ''),
                    'group_name' => ($row['group'] ?? '') !== '' ? $row['group'] : null,
                    'sort_order' => $ri + 1,
                ]);
                $tasks = [];
                foreach ($row['tasks'] ?? [] as $ti => $t) {
                    $kind = ($t['kind'] ?? 'task') === 'milestone' ? 'milestone' : 'task';
                    $tasks[] = [
                        'gantt_chart_id' => $chart->id,
                        'gantt_row_id' => $r->id,
                        'title' => (string) ($t['title'] ?? ''),
                        'kind' => $kind,
                        'start_on' => $t['startOn'],
                        'end_on' => $kind === 'milestone' ? $t['startOn'] : ($t['endOn'] ?? $t['startOn']),
                        'color' => $t['color'] ?? '#3b50cc',
                        'progress' => (int) ($t['progress'] ?? 0),
                        'note' => $t['note'] ?? null,
                        'sort_order' => $ti + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($tasks) {
                    GanttTask::insert($tasks);
                }
            }

            return $chart;
        });

        $this->info(sprintf('登録しました: id=%d "%s" (%s - %s) 行 %d / 区間 %d / user=%s', $chart->id, $chart->title, $chart->start_on->toDateString(), $chart->end_on->toDateString(), $chart->rows()->count(), $chart->tasks()->count(), $user->email));

        return self::SUCCESS;
    }
}
