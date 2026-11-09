<?php

namespace App\Console\Commands;

use App\Models\CardDeck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 学習カードのデータ（database/data/cards_*.json）を登録する。
 *   php artisan cards:import --file=database/data/cards_tetsuryoku_grammar.json
 * カードは全ユーザー共通。束は key、カードは (束, no) をキーに upsert するので、再実行しても回答履歴・復習状態は残る。
 * JSON 形式: { "deck": { "key": "tetsuryoku_grammar", "subject": "english", "name": "鉄緑会 英語実践講座 英文法", "shortName": "鉄緑会 英文法",
 *   "studentOnly": true, "resourceBookTitle": "鉄緑会 英語実践講座 英文法", "structure": [[章番号, 章名, 先頭 no, 末尾 no], ...], "sortOrder": 1 },
 *   "items": [ { "no": 1, "chapterNo": 1, "chapter": "前期 第1週 文型", "section": "1.1 …", "kind": "example",
 *     "front": "英文", "back": "和訳", "extra": { "blanks": [[開始, 終了], ...] }, "page": 12, "bookPage": "1",
 *     "problemImage": "deck/p0001.jpg", "answerImage": null } ] }
 * 画像ファイルは storage/app/private/cards/ 以下に別途置く（Git 管理外。本番へは WinSCP で put）。
 * --prune を付けると JSON に無い no のカードを削除する。
 * --reset を付けると、この束のカードに対する回答履歴（card_attempts）・復習状態（review_states）・出題履歴（quiz_sessions）を消してから登録する
 *   （no の振り直しなどカードの中身が変わり、古い回答が別のカードに付いてしまうとき）。
 */
class ImportCards extends Command
{
    protected $signature = 'cards:import {--file= : JSON ファイルパス} {--prune : JSON に無いカードを削除する} {--reset : この束の回答履歴・復習状態・出題履歴を消してから登録する（カードの中身が変わったとき）}';

    protected $description = '学習カード（鉄緑会 英文法・合格るシリーズ）のデータを登録する（再実行可）';

    public function handle(): int
    {
        $file = (string) $this->option('file');
        if (! $file || ! is_file($file)) {
            $this->error('ファイルが見つかりません: '.$file);

            return self::FAILURE;
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (! is_array($json) || empty($json['deck']['key']) || ! isset($json['items'])) {
            $this->error('JSON の形式が不正です');

            return self::FAILURE;
        }
        $d = $json['deck'];
        $enc = fn ($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);

        DB::transaction(function () use ($d, $json, $enc) {
            $deck = CardDeck::updateOrCreate(['key' => $d['key']], [
                'subject' => $d['subject'] ?? 'english',
                'name' => mb_substr((string) $d['name'], 0, 100),
                'short_name' => isset($d['shortName']) ? mb_substr((string) $d['shortName'], 0, 40) : null,
                'student_only' => (bool) ($d['studentOnly'] ?? false),
                'resource_book_title' => $d['resourceBookTitle'] ?? null,
                'structure' => $d['structure'] ?? null,
                'sort_order' => (int) ($d['sortOrder'] ?? 0),
            ]);
            if ($this->option('reset')) {
                $ids = DB::table('card_items')->where('card_deck_id', $deck->id)->pluck('id');
                $a = DB::table('card_attempts')->whereIn('card_item_id', $ids)->delete();
                $r = DB::table('review_states')->where('kind', 'card')->whereIn('item_id', $ids)->delete();
                $s = DB::table('quiz_sessions')->where('kind', 'card')->where('ref_id', $deck->id)->delete();
                $this->line("  リセット: 回答 {$a} 件・復習状態 {$r} 件・出題履歴 {$s} 件を削除");
            }
            $now = now();
            $rows = [];
            $nos = [];
            foreach ($json['items'] as $i => $it) {
                $nos[] = (int) $it['no'];
                $rows[] = [
                    'card_deck_id' => $deck->id,
                    'no' => (int) $it['no'],
                    'chapter_no' => (int) ($it['chapterNo'] ?? 0),
                    'chapter' => mb_substr((string) ($it['chapter'] ?? ''), 0, 150),
                    'section' => isset($it['section']) ? mb_substr((string) $it['section'], 0, 150) : null,
                    'kind' => $it['kind'] ?? 'example',
                    'front' => (string) ($it['front'] ?? ''),
                    'back' => $it['back'] ?? null,
                    'extra' => $enc($it['extra'] ?? null),
                    'page' => $it['page'] ?? null,
                    'book_page' => isset($it['bookPage']) ? mb_substr((string) $it['bookPage'], 0, 20) : null,
                    'problem_image' => $it['problemImage'] ?? null,
                    'answer_image' => $it['answerImage'] ?? null,
                    'sort_order' => (int) ($it['sortOrder'] ?? $i),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $update = array_values(array_diff(array_keys($rows[0] ?? []), ['card_deck_id', 'no', 'created_at']));
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('card_items')->upsert($chunk, ['card_deck_id', 'no'], $update);
            }
            if ($this->option('prune')) {
                $deleted = DB::table('card_items')->where('card_deck_id', $deck->id)->whereNotIn('no', $nos)->delete();
                $this->line("  削除: {$deleted} 枚");
            }
            $this->info(sprintf('%s（%s）: %d 枚を登録しました', $deck->name, $deck->key, count($rows)));
        });

        return self::SUCCESS;
    }
}
