<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 家庭教師アカウント管理（owner=生徒のみ）。
 * 家庭教師は role=tutor / student_id=生徒ID で作成され、生徒のデータを閲覧・操作する。
 */
class TutorAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tutors = $request->user()->tutors()->orderBy('id')->get();

        return response()->json([
            'data' => $tutors->map(fn (User $t) => $this->payload($t))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // email カラムをログインIDとして使用（メール形式は必須ではない）
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $tutor = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'tutor',
            'student_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($tutor)], 201);
    }

    /** 氏名変更・パスワード再設定 */
    public function update(Request $request, User $tutor): JsonResponse
    {
        $this->authorizeTutor($request, $tutor);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);

        $tutor->update($data);

        // パスワード変更時は既存トークンを失効させる
        if (array_key_exists('password', $data)) {
            $tutor->tokens()->delete();
        }

        return response()->json(['data' => $this->payload($tutor)]);
    }

    public function destroy(Request $request, User $tutor): JsonResponse
    {
        $this->authorizeTutor($request, $tutor);
        $tutor->tokens()->delete();
        $tutor->delete();

        return response()->json(['message' => 'deleted']);
    }

    private function authorizeTutor(Request $request, User $tutor): void
    {
        abort_unless($tutor->role === 'tutor' && $tutor->student_id === $request->user()->id, 404);
    }

    private function payload(User $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'email' => $t->email,
            'createdOn' => $t->created_at->toDateString(),
        ];
    }
}
