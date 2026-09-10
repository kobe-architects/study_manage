<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 講師の月次請求書。user_id は生徒、tutor_id は講師。
 * status: open → closed → issued → confirmed → paid → done
 */
class TutorInvoice extends Model
{
    public const STATUS_OPEN = 'open';           // 稼働時間入力中

    public const STATUS_CLOSED = 'closed';       // 締め済み（生徒の仮発行待ち）

    public const STATUS_ISSUED = 'issued';       // 生徒が仮発行（講師の内容確認待ち）

    public const STATUS_CONFIRMED = 'confirmed'; // 講師が請求内容確認済み＝正式発行（支払待ち）

    public const STATUS_PAID = 'paid';           // 生徒が支払済み（講師の確認待ち）

    public const STATUS_DONE = 'done';           // 講師が支払確認済み（完了）

    protected $fillable = [
        'user_id', 'tutor_id', 'year', 'month', 'hourly_rate', 'status', 'note',
        'closed_at', 'issued_at', 'confirmed_at', 'paid_at', 'done_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'hourly_rate' => 'integer',
        'closed_at' => 'datetime',
        'issued_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TutorWorkEntry::class)->orderBy('work_on')->orderBy('start_min');
    }
}
