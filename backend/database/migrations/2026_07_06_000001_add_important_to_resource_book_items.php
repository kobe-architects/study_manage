<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_book_items', function (Blueprint $table) {
            $table->boolean('important')->default(false)->after('included');
            $table->index(['resource_book_id', 'important']);
        });
    }

    public function down(): void
    {
        Schema::table('resource_book_items', function (Blueprint $table) {
            $table->dropIndex(['resource_book_id', 'important']);
            $table->dropColumn('important');
        });
    }
};
