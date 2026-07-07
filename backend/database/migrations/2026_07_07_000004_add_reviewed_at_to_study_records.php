<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_records', function (Blueprint $table) {
            // 復習完了日。null は未復習（この記録の復習期限がまだ消化されていない）。
            $table->date('reviewed_at')->nullable()->after('review_on');
        });
    }

    public function down(): void
    {
        Schema::table('study_records', function (Blueprint $table) {
            $table->dropColumn('reviewed_at');
        });
    }
};
