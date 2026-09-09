<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 小テストの1ページ（出題元 PDF ページ・対応する教材行・回答写真・添削・採点） */
class QuizPage extends Model
{
    public const MARKS = ['o', 'tri', 'x'];

    protected $fillable = [
        'quiz_id', 'page_no', 'resource_book_pdf_id', 'pdf_page', 'resource_book_item_id', 'label',
        'ref_pdf_id', 'ref_page', 'answer_path', 'answer_uploaded_at', 'annotations', 'annotated_path',
        'mark', 'score', 'comment',
    ];

    protected $casts = [
        'page_no' => 'integer',
        'pdf_page' => 'integer',
        'ref_page' => 'integer',
        'score' => 'integer',
        'annotations' => 'array',
        'answer_uploaded_at' => 'datetime',
    ];

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
