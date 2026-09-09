<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\ResourceBookController;
use App\Http\Controllers\ResourceBookPdfController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StudyItemController;
use App\Http\Controllers\StudyResourceController;
use App\Http\Controllers\TutorAccountController;
use App\Http\Controllers\VocabularyController;
use App\Http\Controllers\VocabularyQuizController;
use Illuminate\Support\Facades\Route;

// ---- 認証 ----
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // ================================================================
    // 生徒（owner）のみ: 既存のメインアプリ + 家庭教師アカウント管理
    // ================================================================
    Route::middleware('role:owner')->group(function () {
        // ===== メインアプリ（学習管理） =====
        Route::get('/study-items', [StudyItemController::class, 'index']);
        Route::post('/study-items', [StudyItemController::class, 'store']);
        Route::put('/study-items/included', [StudyItemController::class, 'updateIncluded']);
        Route::put('/study-items/{studyItem}', [StudyItemController::class, 'update']);
        Route::delete('/study-items/{studyItem}', [StudyItemController::class, 'destroy']);

        Route::post('/records', [RecordController::class, 'store']);
        Route::get('/records', [RecordController::class, 'index']);
        Route::get('/records/export', [RecordController::class, 'export']);
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

        // ===== 家庭教師アカウント管理 =====
        Route::get('/tutors', [TutorAccountController::class, 'index']);
        Route::post('/tutors', [TutorAccountController::class, 'store']);
        Route::put('/tutors/{tutor}', [TutorAccountController::class, 'update']);
        Route::delete('/tutors/{tutor}', [TutorAccountController::class, 'destroy']);

        // ===== 先生からの課題（生徒側は閲覧のみ） =====
        Route::get('/assignments', [AssignmentController::class, 'index']);
        Route::get('/assignments/{assignment}/items', [AssignmentController::class, 'items']);

        // ===== 教材への PDF 紐づけ（小テスト出題元） =====
        Route::get('/resource-books/{resourceBook}/pdfs', [ResourceBookPdfController::class, 'index']);
        Route::post('/resource-books/{resourceBook}/pdfs/chunk', [ResourceBookPdfController::class, 'uploadChunk']);
        Route::post('/resource-books/{resourceBook}/pdfs', [ResourceBookPdfController::class, 'store']);
        Route::put('/resource-book-pdfs/{pdf}', [ResourceBookPdfController::class, 'update']);
        Route::delete('/resource-book-pdfs/{pdf}', [ResourceBookPdfController::class, 'destroy']);
        Route::get('/resource-book-pdfs/{pdf}/file', [ResourceBookPdfController::class, 'file']);
        Route::get('/resource-book-pdfs/{pdf}/pages/{page}', [ResourceBookPdfController::class, 'page'])->whereNumber('page');

        // ===== 小テスト（生徒: ダウンロード・写真提出・結果閲覧・分析） =====
        Route::get('/quizzes', [QuizController::class, 'index']);
        Route::get('/quizzes/stats', [QuizController::class, 'stats']);
        Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
        Route::get('/quizzes/{quiz}/download', [QuizController::class, 'download']);
        Route::get('/quizzes/{quiz}/result-pdf', [QuizController::class, 'resultPdf']);
        Route::get('/quizzes/{quiz}/pages/{page}/answer-image', [QuizController::class, 'answerImage']);
        Route::get('/quizzes/{quiz}/pages/{page}/annotated-image', [QuizController::class, 'annotatedImage']);
        Route::post('/quizzes/{quiz}/pages/{page}/answer', [QuizController::class, 'uploadAnswer']);
        Route::post('/quizzes/{quiz}/submit', [QuizController::class, 'submit']);

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

    // ================================================================
    // 家庭教師（tutor）のみ: 担当生徒のデータを対象に読み書き
    //   各コントローラは targetUserId() により生徒スコープで動作する
    // ================================================================
    Route::middleware('role:tutor')->prefix('tutor')->group(function () {
        // トップページ用: 生徒の学習記録（期間指定）と統計
        Route::get('/records', [RecordController::class, 'index']);
        Route::get('/records/stats', [RecordController::class, 'stats']);

        // 科目別学習状況（読み取りのみ）
        Route::get('/study-items', [StudyItemController::class, 'index']);

        // カレンダー予定（生徒と共有・模試予定など）
        Route::get('/events', [CalendarEventController::class, 'index']);
        Route::post('/events', [CalendarEventController::class, 'store']);
        Route::delete('/events/{calendarEvent}', [CalendarEventController::class, 'destroy']);

        // 課題の対象選択用ツリー（教材→章→行）
        Route::get('/goals/link-options', [GoalController::class, 'linkOptions']);

        // 課題設定（個別学習データから選択して期限を設定）
        Route::get('/assignments', [AssignmentController::class, 'index']);
        Route::post('/assignments', [AssignmentController::class, 'store']);
        Route::put('/assignments/{assignment}', [AssignmentController::class, 'update']);
        Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);
        Route::get('/assignments/{assignment}/items', [AssignmentController::class, 'items']);

        // 教材への PDF 紐づけ（担当生徒の教材に対して操作）
        Route::get('/resource-books/{resourceBook}/pdfs', [ResourceBookPdfController::class, 'index']);
        Route::post('/resource-books/{resourceBook}/pdfs/chunk', [ResourceBookPdfController::class, 'uploadChunk']);
        Route::post('/resource-books/{resourceBook}/pdfs', [ResourceBookPdfController::class, 'store']);
        Route::put('/resource-book-pdfs/{pdf}', [ResourceBookPdfController::class, 'update']);
        Route::delete('/resource-book-pdfs/{pdf}', [ResourceBookPdfController::class, 'destroy']);
        Route::get('/resource-book-pdfs/{pdf}/file', [ResourceBookPdfController::class, 'file']);
        Route::get('/resource-book-pdfs/{pdf}/pages/{page}', [ResourceBookPdfController::class, 'page'])->whereNumber('page');

        // 小テスト（出題・添削・採点・分析）
        Route::get('/quiz-books', [QuizController::class, 'books']);
        Route::get('/resource-books/{resourceBook}/quiz-rows', [QuizController::class, 'bookRows']);
        Route::get('/quizzes', [QuizController::class, 'index']);
        Route::get('/quizzes/stats', [QuizController::class, 'stats']);
        Route::post('/quizzes', [QuizController::class, 'store']);
        Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
        Route::put('/quizzes/{quiz}', [QuizController::class, 'update']);
        Route::delete('/quizzes/{quiz}', [QuizController::class, 'destroy']);
        Route::get('/quizzes/{quiz}/download', [QuizController::class, 'download']);
        Route::get('/quizzes/{quiz}/result-pdf', [QuizController::class, 'resultPdf']);
        Route::get('/quizzes/{quiz}/pages/{page}/answer-image', [QuizController::class, 'answerImage']);
        Route::get('/quizzes/{quiz}/pages/{page}/annotated-image', [QuizController::class, 'annotatedImage']);
        Route::post('/quizzes/{quiz}/pages/{page}/annotations', [QuizController::class, 'saveAnnotations']); // multipart のため POST
        Route::put('/quizzes/{quiz}/pages/{page}/grade', [QuizController::class, 'grade']);
        Route::post('/quizzes/{quiz}/finish', [QuizController::class, 'finish']);
        Route::post('/quizzes/{quiz}/reopen', [QuizController::class, 'reopen']);
    });
});
