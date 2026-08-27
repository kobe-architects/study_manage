<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * データ操作の対象ユーザーID。owner は自分自身、tutor は担当生徒。
     * 学習データはすべて生徒(owner)に属するため、家庭教師からのリクエストは
     * 生徒スコープで読み書きする（公開ルートは routes/api.php で制限）。
     */
    protected function targetUserId(Request $request): int
    {
        return $this->targetUser($request)->id;
    }

    /** データ操作の対象ユーザー（owner=自分 / tutor=担当生徒） */
    protected function targetUser(Request $request): User
    {
        $target = $request->user()->targetStudent();
        abort_if($target === null, 403, '担当生徒が設定されていません。');

        return $target;
    }
}
