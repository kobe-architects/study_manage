<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 英文法・語法の問題（スクランブル英文法・語法、英文法ファーストステップ）。全ユーザー共通。
 * format: choice（選択） / error（誤り指摘） / order（語句整序） / fill（空所補充） / image（画像。ファーストステップは全問これで、
 *   answers があれば記入して自動採点、choice_count があれば ア〜エ の記号選択、どちらも無ければ答えを見て自己判定）
 */
class GrammarQuestion extends Model
{
    public const BOOKS = [
        'grammar' => '英文法', 'usage' => '語法', 'vocab' => '語い', 'idiom' => 'イディオム',
        'conversation' => '会話表現', 'pronunciation' => '発音・アクセント', 'special' => '読解・作文の文法',
        'first_step' => 'ファーストステップ', // 高校リード問題集 英文法ファーストステップ（STEP 1・STEP 2・まとめのテスト・総合問題。全問 image 形式）
    ];

    protected $fillable = [
        'book', 'no', 'chapter_no', 'chapter', 'theme', 'format', 'format_label', 'importance',
        'instruction', 'question', 'choices', 'answer_index', 'answer_text', 'answers', 'answer_note',
        'missing', 'unneeded', 'translation', 'source', 'note', 'explanation', 'explanation_page',
        'problem_image', 'answer_image', 'explanation_image', 'choice_count',
    ];

    protected $casts = [
        'no' => 'integer',
        'chapter_no' => 'integer',
        'theme' => 'integer',
        'choices' => 'array',
        'answer_index' => 'integer',
        'choice_count' => 'integer',
        'answers' => 'array',
        'missing' => 'array',
        'unneeded' => 'array',
    ];

    public function attempts(): HasMany
    {
        return $this->hasMany(GrammarAttempt::class);
    }
}
