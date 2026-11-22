<?php

namespace App\Http\Controllers;

use App\Models\MajorCategory;
use App\Models\MidCategory;
use App\Models\QuestionAttachment;
use App\Models\QuestionMessage;
use App\Models\QuestionRead;
use App\Models\QuestionThread;
use App\Models\Subject;
use App\Models\User;
use App\Support\ImageTools;
use App\Support\LineNotify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 質問事項（生徒 ⇄ 講師の質問スレッド）。データはすべて生徒に属し、講師は targetUserId で同じスレッドを読み書きする。
 * - スレッド: タイトル・科目・大分類・中分類は任意。status（質問中／解決済み）の変更は生徒だけ
 * - メッセージ: 装飾付きの文字（簡易タグ）＋添付（画像は JPEG に正規化してサムネイルも作る。ファイルはそのまま）。
 *   添付は先に片（チャンク）でアップロードし（共有サーバーの post_max_size を超えないため）、メッセージ送信時に uploadId で確定する
 * - 既読: スレッドを開いたときに自分の既読位置を進める。未読数は「自分以外のメッセージで既読位置より新しいもの」
 * - LINE 通知: 送信時に notifyLine=true なら、生徒 → 担当講師たち／講師 → 生徒 へ送る（連携していなければ何もしない）
 */
class QuestionController extends Controller
{
    private const MAX_ATTACHMENT_BYTES = 50 * 1024 * 1024;

    private const WITH = ['subject', 'major', 'mid', 'creator:id,name'];

    // ====================== スレッド ======================

    public function index(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $me = $request->user()->id;
        $threads = QuestionThread::with(self::WITH)->where('user_id', $userId)
            ->orderByDesc('last_message_at')->orderByDesc('id')->get();
        $ids = $threads->pluck('id')->all();
        $unread = $this->unreadCounts($ids, $me);
        // 最後のメッセージ（抜粋用）
        $lastIds = QuestionMessage::whereIn('thread_id', $ids)->select(DB::raw('MAX(id) as id'))->groupBy('thread_id')->pluck('id')->all();
        $lasts = QuestionMessage::with('user:id,name')->whereIn('id', $lastIds)->get()->keyBy('thread_id');

        return response()->json([
            'data' => $threads->map(fn (QuestionThread $t) => $this->threadPayload($t, (int) ($unread[$t->id] ?? 0), $lasts->get($t->id)))->values(),
            'unreadThreads' => count(array_filter($unread, fn ($c) => $c > 0)),
        ]);
    }

    /** 未読のあるスレッド数（ナビのバッジ用） */
    public function unreadCount(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $me = $request->user()->id;
        $ids = QuestionThread::where('user_id', $userId)->pluck('id')->all();
        $unread = $this->unreadCounts($ids, $me);

        return response()->json(['data' => ['threads' => count(array_filter($unread, fn ($c) => $c > 0)), 'messages' => array_sum($unread)]]);
    }

    /** スレッドを新規作成（最初のメッセージと一緒に） */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'subjectId' => ['nullable', 'integer'],
            'majorId' => ['nullable', 'integer'],
            'midId' => ['nullable', 'integer'],
            'body' => ['nullable', 'string', 'max:20000'],
            'notifyLine' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*.uploadId' => ['required', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.mime' => ['nullable', 'string', 'max:120'],
        ]);
        $body = trim((string) ($data['body'] ?? ''));
        abort_if($body === '' && empty($data['attachments']), 422, 'メッセージか添付を入れてください。');
        $cat = $this->resolveCategory($data);

        $thread = DB::transaction(function () use ($request, $userId, $data, $cat, $body) {
            $thread = QuestionThread::create($cat + [
                'user_id' => $userId,
                'title' => $this->cleanTitle($data['title'] ?? null),
                'status' => 'open',
                'created_by' => $request->user()->id,
                'last_message_at' => now(),
                'message_count' => 0,
            ]);
            $this->appendMessage($request, $thread, $body, $data['attachments'] ?? [], (bool) ($data['notifyLine'] ?? false), true);

            return $thread;
        });

        return response()->json(['data' => $this->detail($request, $thread->fresh(self::WITH))], 201);
    }

    /** スレッドの中身（メッセージと添付）。開いた時点で自分の既読位置を進める */
    public function show(Request $request, QuestionThread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        $thread->load(self::WITH);
        $this->markRead($thread, $request->user()->id);

        return response()->json(['data' => $this->detail($request, $thread)]);
    }

    /** タイトル・分類・ステータスの変更（ステータスは生徒だけ） */
    public function update(Request $request, QuestionThread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'subjectId' => ['sometimes', 'nullable', 'integer'],
            'majorId' => ['sometimes', 'nullable', 'integer'],
            'midId' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'string', Rule::in(QuestionThread::STATUSES)],
        ]);
        $attrs = [];
        if (array_key_exists('title', $data)) {
            $attrs['title'] = $this->cleanTitle($data['title']);
        }
        if (array_key_exists('subjectId', $data) || array_key_exists('majorId', $data) || array_key_exists('midId', $data)) {
            $attrs = array_merge($attrs, $this->resolveCategory([
                'subjectId' => $data['subjectId'] ?? $thread->subject_id,
                'majorId' => array_key_exists('majorId', $data) ? $data['majorId'] : $thread->major_category_id,
                'midId' => array_key_exists('midId', $data) ? $data['midId'] : $thread->mid_category_id,
            ]));
        }
        if (array_key_exists('status', $data)) {
            abort_if($request->user()->isTutor(), 403, 'ステータスは生徒が変更します。');
            $attrs['status'] = $data['status'];
        }
        $thread->update($attrs);
        $thread->load(self::WITH);
        $me = $request->user()->id;
        $unread = $this->unreadCounts([$thread->id], $me);
        $last = QuestionMessage::with('user:id,name')->where('thread_id', $thread->id)->orderByDesc('id')->first();

        return response()->json(['data' => $this->threadPayload($thread, (int) ($unread[$thread->id] ?? 0), $last)]);
    }

    /** スレッドの削除（生徒はすべて、講師は自分が立てたものだけ） */
    public function destroy(Request $request, QuestionThread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        abort_if($request->user()->isTutor() && $thread->created_by !== $request->user()->id, 403, '生徒が立てたスレッドは削除できません。');
        Storage::disk('local')->deleteDirectory($this->dirOf($thread));
        $thread->delete();

        return response()->json(['message' => 'deleted']);
    }

    // ====================== メッセージ ======================

    public function storeMessage(Request $request, QuestionThread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:20000'],
            'notifyLine' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*.uploadId' => ['required', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.mime' => ['nullable', 'string', 'max:120'],
        ]);
        $body = trim((string) ($data['body'] ?? ''));
        abort_if($body === '' && empty($data['attachments']), 422, 'メッセージか添付を入れてください。');

        $message = DB::transaction(fn () => $this->appendMessage($request, $thread, $body, $data['attachments'] ?? [], (bool) ($data['notifyLine'] ?? false), false));

        return response()->json(['data' => $this->messagePayload($message->fresh(['user:id,name,role', 'attachments']))], 201);
    }

    /** メッセージの本文を編集（書いた本人だけ。添付はそのまま。編集日時を残す） */
    public function updateMessage(Request $request, QuestionMessage $message): JsonResponse
    {
        $this->authorizeThread($request, $message->thread);
        abort_unless($message->user_id === $request->user()->id, 403, '自分のメッセージだけ編集できます。');
        $data = $request->validate(['body' => ['nullable', 'string', 'max:20000']]);
        $body = trim((string) ($data['body'] ?? ''));
        abort_if($body === '' && ! $message->attachments()->exists(), 422, 'メッセージを空にはできません。');
        $message->update(['body' => $body, 'edited_at' => now()]);

        return response()->json(['data' => $this->messagePayload($message->fresh(['user:id,name,role', 'attachments']))]);
    }

    /** メッセージの削除（書いた本人だけ。添付も消す。最後の 1 件を消してもスレッドは残す） */
    public function destroyMessage(Request $request, QuestionMessage $message): JsonResponse
    {
        $thread = $message->thread;
        $this->authorizeThread($request, $thread);
        abort_unless($message->user_id === $request->user()->id, 403, '自分のメッセージだけ削除できます。');
        $disk = Storage::disk('local');
        foreach ($message->attachments as $a) {
            $disk->delete(array_filter([$a->path, $a->thumb_path]));
        }
        $message->delete();
        $last = QuestionMessage::where('thread_id', $thread->id)->orderByDesc('id')->first();
        $thread->update([
            'message_count' => QuestionMessage::where('thread_id', $thread->id)->count(),
            'last_message_at' => $last?->created_at ?? $thread->created_at,
        ]);

        return response()->json(['message' => 'deleted']);
    }

    // ====================== 添付 ======================

    /** 添付の片（チャンク）を受け取る（共有ファイルと同じ方式。4MB ずつ） */
    public function uploadChunk(Request $request): JsonResponse
    {
        $this->targetUserId($request);
        $data = $request->validate([
            'uploadId' => ['required', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:200'],
            'chunk' => ['required', 'file', 'max:8192'],
        ]);
        $disk = Storage::disk('local');
        $disk->makeDirectory('tmp-uploads');
        $abs = $disk->path('tmp-uploads/q-'.$data['uploadId'].'.part');
        if ((int) $data['index'] === 0 && is_file($abs)) {
            unlink($abs);
        }
        $in = fopen($request->file('chunk')->getRealPath(), 'rb');
        $out = fopen($abs, 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $size = filesize($abs);
        if ($size > self::MAX_ATTACHMENT_BYTES) {
            unlink($abs);
            abort(422, 'ファイルが大きすぎます（1 ファイルの上限 50MB）。');
        }

        return response()->json(['data' => ['received' => (int) $data['index'], 'size' => $size]]);
    }

    /** 添付の本体（?v=thumb でサムネイル、?download=1 でダウンロード） */
    public function attachment(Request $request, QuestionAttachment $attachment): StreamedResponse
    {
        $this->authorizeThread($request, $attachment->message->thread);
        $disk = Storage::disk('local');
        $path = $request->query('v') === 'thumb' && $attachment->thumb_path ? $attachment->thumb_path : $attachment->path;
        abort_unless($disk->exists($path), 404);
        $headers = ['Cache-Control' => 'private, max-age=604800'];
        if ($request->boolean('download') || $attachment->kind !== 'image') {
            return $disk->download($path, $attachment->name, $headers);
        }

        return $disk->response($path, null, $headers + ['Content-Type' => 'image/jpeg']);
    }

    // ====================== 内部処理 ======================

    private function authorizeThread(Request $request, QuestionThread $thread): void
    {
        abort_unless($thread->user_id === $this->targetUserId($request), 403);
    }

    private function dirOf(QuestionThread $thread): string
    {
        return 'questions/'.$thread->user_id.'/'.$thread->id;
    }

    private function cleanTitle(?string $title): ?string
    {
        $t = trim((string) $title);

        return $t === '' ? null : mb_substr($t, 0, 200);
    }

    /** 科目 → 大分類 → 中分類 の整合をとる（上位が無ければ下位も無し。存在しない ID は無視） */
    private function resolveCategory(array $data): array
    {
        $subjectId = ! empty($data['subjectId']) ? Subject::whereKey((int) $data['subjectId'])->value('id') : null;
        $majorId = $subjectId && ! empty($data['majorId']) ? MajorCategory::whereKey((int) $data['majorId'])->where('subject_id', $subjectId)->value('id') : null;
        $midId = $majorId && ! empty($data['midId']) ? MidCategory::whereKey((int) $data['midId'])->where('major_category_id', $majorId)->value('id') : null;

        return ['subject_id' => $subjectId, 'major_category_id' => $majorId, 'mid_category_id' => $midId];
    }

    /** メッセージを追加（添付の確定・スレッドの更新・自分の既読・LINE 通知） */
    private function appendMessage(Request $request, QuestionThread $thread, string $body, array $attachments, bool $notifyLine, bool $isNewThread): QuestionMessage
    {
        $sender = $request->user();
        $message = QuestionMessage::create([
            'thread_id' => $thread->id,
            'user_id' => $sender->id,
            'body' => $body,
            'line_notified' => false,
        ]);
        foreach ($attachments as $a) {
            $this->attachUpload($thread, $message, $a['uploadId'], $a['name'], $a['mime'] ?? null);
        }
        $thread->update([
            'last_message_at' => $message->created_at,
            'message_count' => QuestionMessage::where('thread_id', $thread->id)->count(),
        ]);
        $this->markRead($thread, $sender->id, $message->id);

        if ($notifyLine) {
            $sent = $this->notify($thread, $message, $sender, $isNewThread);
            if ($sent) {
                $message->update(['line_notified' => true]);
            }
        }

        return $message;
    }

    /** 一時ファイル（片の結合済み）を添付として保存する。画像は JPEG に正規化してサムネイルも作る */
    private function attachUpload(QuestionThread $thread, QuestionMessage $message, string $uploadId, string $name, ?string $mime): void
    {
        $disk = Storage::disk('local');
        $tmpRel = 'tmp-uploads/q-'.$uploadId.'.part';
        abort_unless($disk->exists($tmpRel), 422, '添付のアップロードデータが見つかりません。もう一度添付してください。');
        $tmpAbs = $disk->path($tmpRel);
        $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', '_', $name) ?? '') ?: 'file';
        $name = mb_substr($name, 0, 255);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $dir = $this->dirOf($thread);
        $disk->makeDirectory($dir);
        $uuid = (string) Str::uuid();

        $isImage = ($mime && str_starts_with($mime, 'image/')) || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'], true);
        $size = @getimagesize($tmpAbs);
        if ($isImage && $size && ! empty($size[0])) {
            $bytes = (string) file_get_contents($tmpAbs);
            $full = ImageTools::normalizeJpeg($bytes, 2400, 88);
            $thumb = ImageTools::normalizeJpeg($bytes, 480, 78);
            $fsize = @getimagesizefromstring($full);
            $disk->put($dir.'/'.$uuid.'.jpg', $full);
            $disk->put($dir.'/'.$uuid.'_t.jpg', $thumb);
            $disk->delete($tmpRel);
            $message->attachments()->create([
                'kind' => 'image',
                'name' => preg_replace('/\.[A-Za-z0-9]+$/', '', $name).'.jpg',
                'mime' => 'image/jpeg',
                'path' => $dir.'/'.$uuid.'.jpg',
                'thumb_path' => $dir.'/'.$uuid.'_t.jpg',
                'size_bytes' => strlen($full),
                'width' => (int) ($fsize[0] ?? 0),
                'height' => (int) ($fsize[1] ?? 0),
            ]);

            return;
        }
        $rel = $dir.'/'.$uuid.($ext !== '' ? '.'.preg_replace('/[^a-z0-9]/', '', $ext) : '');
        $disk->move($tmpRel, $rel);
        $message->attachments()->create([
            'kind' => 'file',
            'name' => $name,
            'mime' => $mime ? mb_substr($mime, 0, 120) : null,
            'path' => $rel,
            'thumb_path' => null,
            'size_bytes' => filesize($disk->path($rel)),
        ]);
    }

    /** 既読位置を進める（指定が無ければスレッドの最後のメッセージまで） */
    private function markRead(QuestionThread $thread, int $userId, ?int $upTo = null): void
    {
        $last = $upTo ?? (int) QuestionMessage::where('thread_id', $thread->id)->max('id');
        $read = QuestionRead::firstOrNew(['thread_id' => $thread->id, 'user_id' => $userId]);
        if ($last > (int) $read->last_read_message_id) {
            $read->last_read_message_id = $last;
        }
        $read->read_at = now();
        $read->save();
    }

    /** スレッドごとの未読数（自分以外のメッセージで、自分の既読位置より新しいもの） */
    private function unreadCounts(array $threadIds, int $me): array
    {
        if (! $threadIds) {
            return [];
        }
        $rows = DB::table('question_messages as m')
            ->leftJoin('question_reads as r', function ($j) use ($me) {
                $j->on('r.thread_id', '=', 'm.thread_id')->where('r.user_id', '=', $me);
            })
            ->whereIn('m.thread_id', $threadIds)
            ->where('m.user_id', '!=', $me)
            ->whereRaw('m.id > COALESCE(r.last_read_message_id, 0)')
            ->groupBy('m.thread_id')
            ->select('m.thread_id', DB::raw('COUNT(*) as c'))
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->thread_id] = (int) $r->c;
        }

        return $out;
    }

    /** LINE 通知。生徒が送れば担当講師たちへ、講師が送れば生徒へ。1 人以上に送れた（連携済み）か */
    private function notify(QuestionThread $thread, QuestionMessage $message, User $sender, bool $isNewThread): bool
    {
        $title = $thread->title ?: self::excerpt($message->body, 30);
        $excerpt = self::excerpt($message->body, 120);
        $count = $message->attachments()->count();
        $tail = $excerpt !== '' ? "\n{$excerpt}" : '';
        if ($count > 0) {
            $tail .= "\n（添付 {$count} 件）";
        }
        $recipients = [];
        if ($sender->isTutor()) {
            $text = "{$sender->name}先生から質問「{$title}」に返信があります。{$tail}";
            $recipients[] = $thread->user;
        } else {
            $text = $isNewThread
                ? "{$sender->name}さんから新しい質問があります。\n「{$title}」{$tail}"
                : "{$sender->name}さんから質問「{$title}」に新しいメッセージがあります。{$tail}";
            $recipients = $sender->tutors()->get()->all();
        }
        $sent = false;
        foreach ($recipients as $u) {
            if ($u && $u->line_user_id) {
                LineNotify::push($u, $text);
                $sent = true;
            }
        }

        return $sent;
    }

    /** 装飾タグを外した抜粋（通知・一覧用） */
    public static function excerpt(string $body, int $len): string
    {
        // 返信先の引用（[re=ID]…[/re]）は丸ごと外し、他の装飾タグは外して文字だけにする
        $t = preg_replace('/\[re=\d+\].*?\[\/re\]/su', '', $body) ?? $body;
        $t = preg_replace('/\[\/?(?:b|s|u|m|q|re|color)(?:=[^\]]*)?\]/u', '', $t) ?? $t;
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);

        return mb_strlen($t) > $len ? mb_substr($t, 0, $len).'…' : $t;
    }

    private function threadPayload(QuestionThread $t, int $unread, ?QuestionMessage $last): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'subjectId' => $t->subject_id,
            'subjectName' => $t->subject?->name,
            'majorId' => $t->major_category_id,
            'majorName' => $t->major?->name,
            'midId' => $t->mid_category_id,
            'midName' => $t->mid?->name,
            'status' => $t->status,
            'createdBy' => $t->created_by,
            'createdByName' => $t->creator?->name,
            'createdAt' => $t->created_at?->toDateTimeString(),
            'lastMessageAt' => $t->last_message_at?->toDateTimeString(),
            'messageCount' => (int) $t->message_count,
            'unreadCount' => $unread,
            'lastMessage' => $last ? [
                'excerpt' => self::excerpt($last->body, 80),
                'userName' => $last->user?->name,
                'userId' => $last->user_id,
                'hasAttachment' => $last->attachments()->exists(),
            ] : null,
        ];
    }

    private function messagePayload(QuestionMessage $m): array
    {
        return [
            'id' => $m->id,
            'threadId' => $m->thread_id,
            'userId' => $m->user_id,
            'userName' => $m->user?->name,
            'userRole' => $m->user?->role,
            'body' => $m->body,
            'lineNotified' => (bool) $m->line_notified,
            'createdAt' => $m->created_at?->toDateTimeString(),
            'editedAt' => $m->edited_at?->toDateTimeString(),
            'attachments' => $m->attachments->map(fn (QuestionAttachment $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'name' => $a->name,
                'mime' => $a->mime,
                'sizeBytes' => (int) $a->size_bytes,
                'width' => $a->width,
                'height' => $a->height,
            ])->values(),
        ];
    }

    private function detail(Request $request, QuestionThread $t): array
    {
        $me = $request->user()->id;
        $messages = $t->messages()->with(['user:id,name,role', 'attachments'])->get();
        $last = $messages->last();
        $payload = $this->threadPayload($t, 0, $last);
        $payload['messages'] = $messages->map(fn (QuestionMessage $m) => $this->messagePayload($m))->values();
        $payload['canChangeStatus'] = ! $request->user()->isTutor();
        $payload['canDelete'] = ! $request->user()->isTutor() || $t->created_by === $me;

        return $payload;
    }
}
