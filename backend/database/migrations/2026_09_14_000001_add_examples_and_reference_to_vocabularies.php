<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 単語に「複数の例文（意味①②に対応）」と「参考（教材由来の語源・関連語など）」を追加する。
 * memo は利用者が覚え方のコツを書く欄なので、LEAP basic 取り込み時に memo へ入れていた教材由来の
 * テキスト（"No.N ..."）は reference_note へ移し、memo を空にする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->json('examples')->nullable()->after('example_explanation');
            $table->text('reference_note')->nullable()->after('examples');
        });

        $sectionIds = DB::table('study_resource_sections')
            ->join('study_resources', 'study_resources.id', '=', 'study_resource_sections.study_resource_id')
            ->where('study_resources.name', 'LEAP basic')
            ->pluck('study_resource_sections.id');
        if ($sectionIds->isNotEmpty()) {
            DB::table('vocabularies')
                ->whereIn('study_resource_section_id', $sectionIds)
                ->where('memo', 'like', 'No.%')
                ->update(['reference_note' => DB::raw('memo'), 'memo' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->dropColumn(['examples', 'reference_note']);
        });
    }
};
