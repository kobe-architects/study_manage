<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 講師の稼働時間（日付・開始〜終了。30分刻み） */
class TutorWorkEntry extends Model
{
    protected $fillable = ['tutor_invoice_id', 'work_on', 'start_min', 'end_min', 'note'];

    protected $casts = ['work_on' => 'date', 'start_min' => 'integer', 'end_min' => 'integer'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TutorInvoice::class, 'tutor_invoice_id');
    }
}
