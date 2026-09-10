<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 講師の請求管理。
 * tutor_invoices: 月ごとの請求書（講師→生徒）。ステータスで発行フローを管理する。
 *   open(入力中) → closed(締め済み) → issued(生徒が仮発行) → confirmed(講師が請求内容確認済み=正式発行)
 *   → paid(生徒が支払済み) → done(講師が支払確認済み)
 * tutor_work_entries: 稼働時間（日付と開始〜終了、30分刻み）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');   // 生徒
            $table->foreignId('tutor_id');  // 講師
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('hourly_rate')->default(0); // 円/時
            $table->string('status', 20)->default('open');
            $table->text('note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->unique(['tutor_id', 'year', 'month']);
            $table->index(['user_id', 'year', 'month']);
        });

        Schema::create('tutor_work_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_invoice_id')->index();
            $table->date('work_on');
            $table->unsignedSmallInteger('start_min'); // 0〜1440（30分刻み）
            $table->unsignedSmallInteger('end_min');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_work_entries');
        Schema::dropIfExists('tutor_invoices');
    }
};
