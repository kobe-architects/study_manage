<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 単語に「意味の補足」列を追加する。
 * 意味（meaning）の補足説明を保持する。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->text('meaning_supplement')->nullable()->after('meaning');
        });
    }

    public function down(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->dropColumn('meaning_supplement');
        });
    }
};
