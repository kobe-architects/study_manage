<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 家庭教師アカウントのパスワードを生徒(owner)が確認できるようにする。
 * ログイン照合は従来どおり password(ハッシュ) で行い、plain_password は
 * 表示専用（Eloquent の encrypted キャストで暗号化保存）。
 * 家庭教師アカウントは生徒本人が作成・管理するため、生徒のみに返す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('plain_password')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('plain_password');
        });
    }
};
