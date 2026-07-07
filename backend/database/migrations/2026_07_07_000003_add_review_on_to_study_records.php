<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_records', function (Blueprint $table) {
            // 復習期限日。null は復習不要（既存データ・デフォルト）。
            $table->date('review_on')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('study_records', function (Blueprint $table) {
            $table->dropColumn('review_on');
        });
    }
};
