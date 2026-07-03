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
        ]);

        $map = [
            'name' => 'name', 'school' => 'school', 'examDate' => 'exam_date',
            'defaultType' => 'default_type', 'reminder' => 'reminder',
            'weeklyReport' => 'weekly_report', 'hideEmpty' => 'hide_empty',
            'startScreen' => 'start_screen',
        ];
        $payload = [];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $data)) {
                $payload[$col] = $data[$in];
            }
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
        ]]);
    }
}
