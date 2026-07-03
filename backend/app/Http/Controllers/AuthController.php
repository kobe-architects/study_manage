<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['メールアドレスまたはパスワードが正しくありません。'],
            ]);
        }

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    private function userPayload(User $user): array
    {
        $settings = $user->settings ?? UserSetting::create(['user_id' => $user->id, 'name' => $user->name]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'settings' => $this->settingsPayload($settings),
        ];
    }

    private function settingsPayload(UserSetting $s): array
    {
        return [
            'name' => $s->name,
            'school' => $s->school,
            'examDate' => $s->exam_date?->toDateString(),
            'defaultType' => $s->default_type,
            'reminder' => $s->reminder,
            'weeklyReport' => $s->weekly_report,
            'hideEmpty' => $s->hide_empty,
            'startScreen' => $s->start_screen,
        ];
    }
}
