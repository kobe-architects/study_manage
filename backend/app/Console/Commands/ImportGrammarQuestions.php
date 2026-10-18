<?php

namespace App\Console\Commands;

use App\Models\GrammarQuestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * スクランブル英文法・語法の問題データ（database/data/scramble_grammar.json）を登録する。
 *   php artisan grammar:import [--file=path]
 * 問題は全ユーザー共通。(book, no) をキーに upsert するので、再実行しても回答履歴は残る。
 *
 * JSON 形式: { "books": [ { "key": "grammar", "name": "英文法", "questions": [ { "no": 1, "chapterNo": 1,
 *   "chapter": "第1章 時制", "theme": 1, "format": "choice", "formatLabel": "選択", "importance": null,
 *   "instruction": null, "question": "...", "choices": ["..."], "answerIndex": 1, "answerText": "...",
 *   "answers": null, "answerNote": null, "missing": [], "unneeded": [], "translation": "...", "source": "...", "note": null } ] } ] }
 * （JSON の生成は docs/other の Excel から。変換スクリプトはこのコマンドの説明に従う形式で出力する）
 */
class ImportGrammarQuestions extends Command
{
    protected $signature = 'grammar:import {--file= : JSON ファイルパス}';

    protected $description = 'スクランブル英文法・語法の問題データを登録する（再実行可）';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('data/scramble_grammar.json');
        if (! is_file($file)) {
            $this->error('ファイルが見つかりません: '.$file);

            return self::FAILURE;
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (! is_array($json) || empty($json['books'])) {
            $this->error('JSON の形式が不正です');

            return self::FAILURE;
        }

        $enc = fn ($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);
        $total = 0;
        DB::transaction(function () use ($json, $enc, &$total) {
            $now = now();
            foreach ($json['books'] as $book) {
                $key = $book['key'];
                abort_unless(isset(GrammarQuestion::BOOKS[$key]), 422, '不明な問題集: '.$key);
                $rows = [];
                foreach ($book['questions'] as $q) {
                    $rows[] = [
                        'book' => $key,
                        'no' => (int) $q['no'],
                        'chapter_no' => (int) $q['chapterNo'],
                        'chapter' => mb_substr((string) $q['chapter'], 0, 100),
                        'theme' => (int) $q['theme'],
                        'format' => $q['format'],
                        'format_label' => mb_substr((string) $q['formatLabel'], 0, 20),
                        'importance' => $q['importance'] ?? null,
                        'instruction' => $q['instruction'] ?? null,
                        'question' => $q['question'],
                        'choices' => $enc($q['choices'] ?? null),
                        'answer_index' => $q['answerIndex'] ?? null,
                        'answer_text' => $q['answerText'] ?? null,
                        'answers' => $enc($q['answers'] ?? null),
                        'answer_note' => isset($q['answerNote']) ? mb_substr((string) $q['answerNote'], 0, 100) : null,
                        'missing' => $enc($q['missing'] ?? null),
                        'unneeded' => $enc($q['unneeded'] ?? null),
                        'translation' => $q['translation'] ?? null,
                        'source' => isset($q['source']) ? mb_substr((string) $q['source'], 0, 100) : null,
                        'note' => $q['note'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                $update = array_values(array_diff(array_keys($rows[0] ?? []), ['book', 'no', 'created_at']));
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('grammar_questions')->upsert($chunk, ['book', 'no'], $update);
                }
                $total += count($rows);
                $this->line(sprintf('  %s（%s）: %d 問', GrammarQuestion::BOOKS[$key], $key, count($rows)));
            }
        });

        $this->info("登録しました: 合計 {$total} 問");

        return self::SUCCESS;
    }
}
