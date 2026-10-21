<?php

namespace App\Http\Controllers;

use App\Models\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'school' => ['sometimes', 'nullable', 'string', 'max:255'],
            'examDate' => ['sometimes', 'nullable', 'date'],
            'defaultType' => ['sometimes', 'in:講義,問題集'],
            'reminder' => ['sometimes', 'boolean'],
            'weeklyReport' => ['sometimes', 'boolean'],
            'hideEmpty' => ['sometimes', 'boolean'],
            'startScreen' => ['sometimes', 'in:home,record,goal'],
            'tutorQuizEnabled' => ['sometimes', 'boolean'],
            'tutorSubjectsEnabled' => ['sometimes', 'boolean'],
            'prefs' => ['sometimes', 'array'], // 画面側の好み。渡したキーだけを上書き（他のキーは残す）
        ]);

        $map = [
            'name' => 'name', 'school' => 'school', 'examDate' => 'exam_date',
            'defaultType' => 'default_type', 'reminder' => 'reminder',
            'weeklyReport' => 'weekly_report', 'hideEmpty' => 'hide_empty',
            'startScreen' => 'start_screen', 'tutorQuizEnabled' => 'tutor_quiz_enabled',
            'tutorSubjectsEnabled' => 'tutor_subjects_enabled',
        ];
        $payload = [];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $data)) {
                $payload[$col] = $data[$in];
            }
        }

        if (array_key_exists('prefs', $data)) {
            $current = UserSetting::where('user_id', $userId)->first()?->prefs; // キャスト済み（配列）
            $payload['prefs'] = array_merge(is_array($current) ? $current : [], $data['prefs']);
        }

        $settings = UserSetting::updateOrCreate(['user_id' => $userId], $payload);

        // 氏名は users テーブルにも反映
        if (isset($payload['name'])) {
            $request->user()->update(['name' => $payload['name']]);
        }

        return response()->json(['data' => [
            'name' => $settings->name,
            'school' => $settings->school,
            'examDate' => $settings->exam_date?->toDateString(),
            'defaultType' => $settings->default_type,
            'reminder' => $settings->reminder,
            'weeklyReport' => $settings->weekly_report,
            'hideEmpty' => $settings->hide_empty,
            'startScreen' => $settings->start_screen,
            'tutorQuizEnabled' => (bool) $settings->tutor_quiz_enabled,
            'tutorSubjectsEnabled' => (bool) $settings->tutor_subjects_enabled,
            'prefs' => $settings->prefs ?: null,
        ]]);
    }
}
