<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 英単語クイズ サブシステム
 * StudyResource > StudyResourceSection > Vocabulary
 *                                          ├─ VocabularyAttempt (回答履歴)
 *                                          └─ VocabularyLearningStat (SM-2 統計)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('study_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('study_resource_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_resource_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('study_resource_id');
        });

        Schema::create('vocabularies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_resource_section_id')->constrained()->cascadeOnDelete();
            $table->string('word', 255);
            $table->string('meaning', 500);
            $table->string('part_of_speech', 50)->nullable();
            $table->tinyInteger('importance')->default(1);     // 0 / 1 / 2
            $table->string('label', 10)->default('normal');     // easy / normal / hard
            $table->string('proficiency', 10)->default('low');  // high / medium / low
            $table->text('memo')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->text('example_sentence')->nullable();
            $table->text('example_translation')->nullable();
            $table->text('example_explanation')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('study_resource_section_id');
        });

        Schema::create('vocabulary_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_correct');
            $table->string('quiz_type', 10);   // choice / input
            $table->timestamp('answered_at');
            $table->timestamps();
            $table->index(['vocabulary_id', 'user_id']);
            $table->index(['user_id', 'answered_at']);
        });

        Schema::create('vocabulary_learning_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('incorrect_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->decimal('ease_factor', 4, 2)->default(2.50);
            $table->unsignedInteger('interval_days')->default(0);
            $table->unsignedInteger('repetition_count')->default(0);
            $table->timestamps();
            $table->unique(['vocabulary_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vocabulary_learning_stats');
        Schema::dropIfExists('vocabulary_attempts');
        Schema::dropIfExists('vocabularies');
        Schema::dropIfExists('study_resource_sections');
        Schema::dropIfExists('study_resources');
    }
};
