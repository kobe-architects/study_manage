<?php

namespace App\Console\Commands;

use App\Models\StudyResource;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LEAP Basic の英単語データ（database/data/leap_basic.json）を単語帳として登録する。
 *   php artisan vocab:import-leap [--user=ID] [--file=path]
 * 同名の単語帳が既にある場合はセクション・単語を入れ替える（学習履歴は単語ごと削除される）。
 *
 * JSON 形式: { "resourceName": "LEAP basic", "sections": [ { "name": "Part 1 / Week 1（1〜40）",
 *   "words": [ { "no": 1, "word": "a", "meaning": "...", "pos": "冠詞", "memo": "...", "example": "...",
 *               "translation": "...", "cefr": "A1", "star": false } ] } ] }
 */
class ImportLeapVocabulary extends Command
{
    protected $signature = 'vocab:import-leap {--user= : 生徒(owner)のユーザーID（省略時は最初の owner）} {--file= : JSON ファイルパス}';

    protected $description = 'LEAP Basic の英単語データを単語帳として登録する';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('data/leap_basic.json');
        if (! is_file($file)) {
            $this->error('ファイルが見つかりません: '.$file);

            return self::FAILURE;
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (! is_array($json) || empty($json['sections'])) {
            $this->error('JSON の形式が不正です');

            return self::FAILURE;
        }

        $user = $this->option('user')
            ? User::find((int) $this->option('user'))
            : User::where('role', 'owner')->orderBy('id')->first();
        if ($user === null) {
            $this->error('ユーザーが見つかりません');

            return self::FAILURE;
        }

        $name = $json['resourceName'] ?? 'LEAP basic';
        $labelOf = fn (?string $cefr) => match ($cefr) {
            'A1' => 'easy',
            'A2' => 'normal',
            null, '' => 'normal',
            default => 'hard', // B1 以上
        };

        $total = 0;
        DB::transaction(function () use ($user, $name, $json, $labelOf, &$total) {
            $resource = StudyResource::where('user_id', $user->id)->where('name', $name)->first();
            if ($resource) {
                // 単語は Eloquent 経由で削除（画像の物理削除フックを効かせる）
                foreach ($resource->sections()->with('vocabularies')->get() as $sec) {
                    $sec->vocabularies->each->delete();
                    $sec->delete();
                }
            } else {
                $resource = StudyResource::create(['user_id' => $user->id, 'name' => $name]);
            }

            foreach ($json['sections'] as $si => $sec) {
                $section = $resource->sections()->create(['name' => $sec['name'], 'sort_order' => $si]);
                $rows = [];
                $now = now();
                foreach ($sec['words'] as $wi => $w) {
                    $memo = trim((string) ($w['memo'] ?? ''));
                    $noPrefix = isset($w['no']) ? 'No.'.$w['no'] : '';
                    $rows[] = [
                        'study_resource_section_id' => $section->id,
                        'word' => mb_substr(trim((string) $w['word']), 0, 255),
                        'meaning' => mb_substr(trim((string) $w['meaning']), 0, 500),
                        'part_of_speech' => $w['pos'] ?? null,
                        'importance' => isset($w['importance']) ? max(0, min(2, (int) $w['importance'])) : (! empty($w['star']) ? 1 : 0),
                        'label' => $labelOf($w['cefr'] ?? null),
                        'proficiency' => 'low',
                        'memo' => trim($noPrefix.($memo !== '' ? ' '.$memo : '')) ?: null,
                        'example_sentence' => ($w['example'] ?? '') !== '' ? $w['example'] : null,
                        'example_translation' => ($w['translation'] ?? '') !== '' ? $w['translation'] : null,
                        'example_explanation' => null,
                        'sort_order' => $wi + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('vocabularies')->insert($chunk);
                }
                $total += count($rows);
            }
        });

        $this->info("登録しました: {$name} / ".count($json['sections']).' セクション / '.$total.' 語（user='.$user->id.'）');

        return self::SUCCESS;
    }
}
