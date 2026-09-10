<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LINE Messaging API（公式アカウント）でのプッシュ通知。
 * LINE_CHANNEL_ACCESS_TOKEN が未設定、または相手が LINE 連携していない場合は何もしない。
 * 通知の失敗で本来の処理（出題・提出など）を失敗させないため、例外は握りつぶしてログに残す。
 */
class LineNotify
{
    private const API = 'https://api.line.me/v2/bot';

    /** 1ユーザーへプッシュ送信 */
    public static function push(?User $user, string $message): void
    {
        $token = config('services.line.channel_access_token');
        if (! $token || $user === null || ! $user->line_user_id) {
            return;
        }
        try {
            $res = Http::withToken($token)->timeout(10)->post(self::API.'/message/push', [
                'to' => $user->line_user_id,
                'messages' => [['type' => 'text', 'text' => mb_substr($message, 0, 4900)]],
            ]);
            if (! $res->successful()) {
                Log::warning('LINE push failed: '.$res->status().' '.$res->body());
            }
        } catch (\Throwable $e) {
            Log::warning('LINE push failed: '.$e->getMessage());
        }
    }

    /** 応答メッセージ（Webhook の replyToken 宛て） */
    public static function reply(string $replyToken, string $message): void
    {
        $token = config('services.line.channel_access_token');
        if (! $token) {
            return;
        }
        try {
            Http::withToken($token)->timeout(10)->post(self::API.'/message/reply', [
                'replyToken' => $replyToken,
                'messages' => [['type' => 'text', 'text' => mb_substr($message, 0, 4900)]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('LINE reply failed: '.$e->getMessage());
        }
    }
}
