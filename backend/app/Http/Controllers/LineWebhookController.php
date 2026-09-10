<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\LineNotify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LINE Messaging API の Webhook。
 * - 友だち追加: 連携コードの送信を案内
 * - テキスト「連携コード」: そのコードを持つアプリユーザー（生徒・講師）と LINE アカウントを紐づける
 * - テキスト「解除」: 紐づけを解除する
 */
class LineWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.line.channel_secret');
        if ($secret === '') {
            return response()->json(['message' => 'not configured']);
        }
        // 署名検証（LINE 以外からのリクエストを拒否）
        $signature = (string) $request->header('X-Line-Signature', '');
        $expected = base64_encode(hash_hmac('sha256', (string) $request->getContent(), $secret, true));
        abort_unless($signature !== '' && hash_equals($expected, $signature), 403);

        foreach ((array) $request->input('events', []) as $event) {
            $this->handleEvent((array) $event);
        }

        return response()->json(['message' => 'ok']);
    }

    private function handleEvent(array $event): void
    {
        $type = $event['type'] ?? '';
        $source = (array) ($event['source'] ?? []);
        // グループ・複数人トークで連携された場合はそのトーク宛てに通知する（push の to は groupId / roomId も可）
        $lineUserId = $source['groupId'] ?? $source['roomId'] ?? $source['userId'] ?? null;
        $replyToken = $event['replyToken'] ?? null;
        if (! $lineUserId) {
            return;
        }

        if ($type === 'follow' && $replyToken) {
            LineNotify::reply($replyToken, "友だち追加ありがとうございます。\n受験ナビの設定画面に表示される「連携コード」をこのトークに送信すると、通知の受け取りを開始できます。");

            return;
        }

        // グループ・複数人トークに招待されたとき
        if (($type === 'join' && $replyToken)) {
            LineNotify::reply($replyToken, "招待ありがとうございます。\n受験ナビの画面に表示される「連携コード」をこのトークに送信すると、このグループに通知をお届けします。");

            return;
        }

        if ($type !== 'message' || ($event['message']['type'] ?? '') !== 'text') {
            return;
        }
        $text = trim((string) ($event['message']['text'] ?? ''));

        // 連携解除
        if ($text === '解除') {
            $n = User::where('line_user_id', $lineUserId)->update(['line_user_id' => null]);
            if ($replyToken) {
                LineNotify::reply($replyToken, $n > 0 ? '通知の連携を解除しました。再開するには連携コードを送信してください。' : '連携されていません。');
            }

            return;
        }

        // 連携コード照合（大文字小文字は無視）
        $code = strtoupper(preg_replace('/\s/u', '', $text) ?? '');
        $user = $code !== '' && strlen($code) <= 16 ? User::where('line_link_code', $code)->first() : null;
        if ($user) {
            $user->update(['line_user_id' => $lineUserId]);
            if ($replyToken) {
                LineNotify::reply($replyToken, "✅ 連携が完了しました（{$user->name}）。\n課題や小テストの通知をこのトークにお届けします。");
            }

            return;
        }

        // 未連携のユーザーからの不明なメッセージには使い方を案内（1対1トークのみ。グループの雑談には反応しない）
        if ($replyToken && ($source['type'] ?? '') === 'user' && ! User::where('line_user_id', $lineUserId)->exists()) {
            LineNotify::reply($replyToken, '連携するには、受験ナビの設定画面に表示される「連携コード」を送信してください。');
        }
    }
}
