<?php

use Illuminate\Support\Facades\Route;

// SPA (Vue) を配信。/api 以外のすべてのパスでビルド済み index.html を返す。
// 静的アセット（/assets/*）は public 上に実体があるため Apache が直接配信する。
Route::get('/{any?}', function () {
    return response()->file(public_path('index.html'));
})->where('any', '^(?!api).*$');
