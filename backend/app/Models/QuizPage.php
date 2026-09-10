<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 小テストの1ページ（出題元 PDF ページ・対応する教材行・回答写真・添削・採点） */
class QuizPage extends Model
{
    public const MARKS = ['o', 'tri', 'x'];

    public const KIND_PDF = 'pdf';

    public const KIND_VOCAB = 'vocab';

    protected $fillable = [
        'quiz_id', 'page_no', 'kind', 'resource_book_pdf_id', 'pdf_page', 'resource_book_item_id', 'label',
        'vocab_spec', 'vocab_words', 'render_path', 'answer_render_path',
        'ref_pdf_id', 'ref_page', 'answer_path', 'answer_uploaded_at', 'annotations', 'annotated_path',
        'mark', 'score', 'max_score', 'comment',
    ];

    protected $casts = [
        'page_no' => 'integer',
        'pdf_page' => 'integer',
        'ref_page' => 'integer',
        'score' => 'integer',
        'max_score' => 'integer',
        'annotations' => 'array',
        'vocab_spec' => 'array',
        'vocab_words' => 'array',
        'answer_uploaded_at' => 'datetime',
    ];

    public function isVocab(): bool
    {
        return $this->kind === self::KIND_VOCAB;
    }

    /** ページ満点（個別指定がなければ小テストの既定値） */
    public function maxScore(?Quiz $quiz = null): int
    {
        return $this->max_score ?? ($quiz ?? $this->quiz)->max_score_per_page;
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function pdf(): BelongsTo
    {
        return $this->belongsTo(ResourceBookPdf::class, 'resource_book_pdf_id');
    }

    public function refPdf(): BelongsTo
    {
        return $this->belongsTo(ResourceBookPdf::class, 'ref_pdf_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ResourceBookItem::class, 'resource_book_item_id');
    }
}
