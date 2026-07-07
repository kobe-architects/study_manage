<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\ResourceBookController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StudyItemController;
use App\Http\Controllers\StudyResourceController;
use App\Http\Controllers\VocabularyController;
use App\Http\Controllers\VocabularyQuizController;
use Illuminate\Support\Facades\Route;

// ---- 認証 ----
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // ===== メインアプリ（学習管理） =====
    Route::get('/study-items', [StudyItemController::class, 'index']);
    Route::post('/study-items', [StudyItemController::class, 'store']);
    Route::put('/study-items/included', [StudyItemController::class, 'updateIncluded']);
    Route::put('/study-items/{studyItem}', [StudyItemController::class, 'update']);
    Route::delete('/study-items/{studyItem}', [StudyItemController::class, 'destroy']);

    Route::post('/records', [RecordController::class, 'store']);
    Route::get('/records/stats', [RecordController::class, 'stats']);
    Route::get('/records/reviews', [RecordController::class, 'reviews']);
    Route::post('/records/{record}/review-complete', [RecordController::class, 'completeReview']);
    Route::delete('/records/{record}', [RecordController::class, 'destroy']);

    // ===== 個別学習一覧データ（教材: 講義/問題集/教科書） =====
    Route::get('/resource-books/template', [ResourceBookController::class, 'template']);
    Route::get('/resource-books', [ResourceBookController::class, 'index']);
    Route::post('/resource-books', [ResourceBookController::class, 'store']);
    Route::post('/resource-books/reorder', [ResourceBookController::class, 'reorder']);
    Route::put('/resource-books/{resourceBook}', [ResourceBookController::class, 'update']);
    Route::delete('/resource-books/{resourceBook}', [ResourceBookController::class, 'destroy']);

    Route::get('/resource-books/{resourceBook}/export', [ResourceBookController::class, 'export']);
    Route::post('/resource-books/{resourceBook}/import', [ResourceBookController::class, 'import']);

    Route::get('/resource-books/{resourceBook}/image', [ResourceBookController::class, 'showImage']);
    Route::post('/resource-books/{resourceBook}/image', [ResourceBookController::class, 'uploadImage']);
    Route::delete('/resource-books/{resourceBook}/image', [ResourceBookController::class, 'deleteImage']);

    Route::get('/resource-books/{resourceBook}/related-problems', [ResourceBookController::class, 'relatedProblems']);
    Route::get('/resource-books/{resourceBook}/rows', [ResourceBookController::class, 'rowsIndex']);
    Route::post('/resource-books/{resourceBook}/rows', [ResourceBookController::class, 'rowStore']);
    Route::put('/resource-books/{resourceBook}/rows/included', [ResourceBookController::class, 'updateIncluded']);
    Route::put('/resource-book-rows/{row}', [ResourceBookController::class, 'rowUpdate']);
    Route::delete('/resource-book-rows/{row}', [ResourceBookController::class, 'rowDestroy']);
    Route::get('/resource-book-rows/{row}/records', [ResourceBookController::class, 'rowRecords']);
    Route::post('/resource-book-rows/{row}/record', [ResourceBookController::class, 'recordRow']);

    Route::get('/goals/link-options', [GoalController::class, 'linkOptions']);
    Route::get('/goals', [GoalController::class, 'index']);
    Route::post('/goals', [GoalController::class, 'store']);
    Route::post('/goals/{goal}/sub-goals', [GoalController::class, 'storeSubGoal']);
    Route::get('/goals/{goal}/link-options', [GoalController::class, 'subLinkOptions']);
    Route::get('/goals/{goal}/items', [GoalController::class, 'linkedItems']);
    Route::put('/goals/{goal}/items/studied', [GoalController::class, 'setItemStudied']);
    Route::put('/goals/{goal}/items', [GoalController::class, 'updateItems']);
    Route::put('/goals/{goal}', [GoalController::class, 'update']);
    Route::delete('/goals/{goal}', [GoalController::class, 'destroy']);

    Route::get('/events', [CalendarEventController::class, 'index']);
    Route::post('/events', [CalendarEventController::class, 'store']);
    Route::delete('/events/{calendarEvent}', [CalendarEventController::class, 'destroy']);

    Route::put('/settings', [SettingController::class, 'update']);

    // ===== 英単語クイズ サブシステム =====
    Route::get('/study-resources', [StudyResourceController::class, 'index']);

    // 単語 CRUD・付帯機能
    Route::get('/study-resources/{studyResource}/vocabularies', [VocabularyController::class, 'indexByResource']);
    Route::post('/sections/{section}/vocabularies', [VocabularyController::class, 'store']);
    Route::put('/vocabularies/{vocabulary}', [VocabularyController::class, 'update']);
    Route::delete('/vocabularies/{vocabulary}', [VocabularyController::class, 'destroy']);
    Route::delete('/study-resources/{studyResource}/vocabularies', [VocabularyController::class, 'destroyAll']);

    Route::get('/vocabularies/template', [VocabularyController::class, 'template']);
    Route::get('/study-resources/{studyResource}/vocabularies/export', [VocabularyController::class, 'export']);
    Route::post('/study-resources/{studyResource}/vocabularies/import', [VocabularyController::class, 'import']);

    Route::get('/vocabularies/{vocabulary}/image', [VocabularyController::class, 'showImage']);
    Route::post('/vocabularies/{vocabulary}/image', [VocabularyController::class, 'uploadImage']);
    Route::delete('/vocabularies/{vocabulary}/image', [VocabularyController::class, 'deleteImage']);

    // 学習系
    Route::get('/study-resources/{studyResource}/quiz', [VocabularyQuizController::class, 'quiz']);
    Route::post('/vocabularies/{vocabulary}/attempt', [VocabularyQuizController::class, 'attempt']);
    Route::get('/study-resources/{studyResource}/vocabularies/stats', [VocabularyQuizController::class, 'stats']);
    Route::get('/study-resources/{studyResource}/vocabularies/incorrect', [VocabularyQuizController::class, 'incorrect']);

    // トップページ用: 英単語の習得進捗（全体 + セクション別）
    Route::get('/vocabulary-progress', [VocabularyQuizController::class, 'homeProgress']);
});
