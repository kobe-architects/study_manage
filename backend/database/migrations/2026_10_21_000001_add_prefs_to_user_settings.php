<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 画面側の細かな好み（間違いノートのペンの太さ・種類・色など）をユーザー設定に JSON で保存する。
 * 端末の localStorage だけだと別の端末で引き継げず、ブラウザのデータ削除で消えるため。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->json('prefs')->nullable()->after('tutor_subjects_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('prefs');
        });
    }
};
