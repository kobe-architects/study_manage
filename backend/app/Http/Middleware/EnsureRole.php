<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ロール制限: role:owner（生徒＝管理者） / role:tutor（家庭教師）。
 * tutor は担当生徒(student_id)が設定されていることも要求する。
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        abort_if($user === null || $user->role !== $role, 403, 'この操作を行う権限がありません。');

        if ($role === 'tutor') {
            abort_if($user->student_id === null, 403, '担当生徒が設定されていません。');
        }

        return $next($request);
    }
}
