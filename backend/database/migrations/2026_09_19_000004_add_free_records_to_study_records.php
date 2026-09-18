<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 学習記録に「科目＋自由入力」の記録を追加できるようにする。
 * 自由入力の記録は study_item_id が null で、subject_id と title（内容）を持つ（type は「自由」）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_records', function (Blueprint $table) {
            $table->foreignId('study_item_id')->nullable()->change();
            $table->foreignId('subject_id')->nullable()->after('study_item_id')->constrained('subjects')->nullOnDelete();
            $table->string('title', 255)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('study_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
            $table->dropColumn('title');
        });
    }
};
