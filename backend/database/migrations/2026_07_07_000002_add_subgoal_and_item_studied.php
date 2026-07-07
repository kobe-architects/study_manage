<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 中間目標: 親目標への参照（親削除で子も削除）
        Schema::table('goals', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('user_id')->constrained('goals')->cascadeOnDelete();
        });

        // 紐づけ項目ごとの「手動 学習済み」フラグ（直接 学習済み設定 用）
        Schema::table('goal_resource_items', function (Blueprint $table) {
            $table->boolean('studied')->default(false)->after('resource_book_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('goal_resource_items', function (Blueprint $table) {
            $table->dropColumn('studied');
        });
        Schema::table('goals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
