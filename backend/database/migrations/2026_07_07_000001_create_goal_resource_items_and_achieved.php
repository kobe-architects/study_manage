<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 目標 ⇔ 個別学習データ（教材の行）の紐づけ（多対多）
        Schema::create('goal_resource_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_book_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['goal_id', 'resource_book_item_id']);
            $table->index('resource_book_item_id');
        });

        // 目標の達成/未達成の記録（null=未記録, true=達成, false=未達成）
        Schema::table('goals', function (Blueprint $table) {
            $table->boolean('achieved')->nullable()->after('target');
        });
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn('achieved');
        });
        Schema::dropIfExists('goal_resource_items');
    }
};
