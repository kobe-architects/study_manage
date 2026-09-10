<?php

namespace App\Http\Controllers;

use App\Models\TutorInvoice;
use App\Models\TutorWorkEntry;
use App\Support\ImageTools;
use App\Support\LineNotify;
use App\Support\PdfTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * 講師の請求管理（講師: 請求書管理 / 生徒: 講師請求管理）。
 *   講師: 稼働時間の登録（日付・開始〜終了 30分刻み）・締め・請求内容確認・支払確認
 *   生徒: 請求書の仮発行・支払済み更新・閲覧
 * ステータス: open → closed → issued → confirmed → paid → done
 * 仮発行・内容確認・支払済み・支払確認の各タイミングで相手側へ LINE 通知する。
 */
class TutorInvoiceController extends Controller
{
    // ====================== 一覧・詳細 ======================

    public function index(Request $request): JsonResponse
    {
        $q = TutorInvoice::with(['tutor:id,name,hourly_rate', 'user:id,name', 'entries'])
            ->where('user_id', $this->targetUserId($request))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('tutor_id');
        if ($request->user()->isTutor()) {
            $q->where('tutor_id', $request->user()->id);
        }

        return response()->json(['data' => $q->get()->map(fn (TutorInvoice $i) => $this->payload($i))->values()]);
    }

    public function show(Request $request, TutorInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $invoice->load(['tutor:id,name,hourly_rate', 'user:id,name', 'entries']);

        return response()->json(['data' => $this->payload($invoice, true)]);
    }

    // ====================== 稼働時間（生徒が登録。講師本人からの登録も許可） ======================

    /** 稼働時間を登録する。該当月の請求書がなければ作成する（時給は直近の請求書から引き継ぎ） */
    public function storeEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tutorId' => ['nullable', 'integer'],
            'workOn' => ['required', 'date'],
            'startMin' => ['required', 'integer', 'min:0', 'max:1410', 'multiple_of:30'],
            'endMin' => ['required', 'integer', 'min:30', 'max:1440', 'multiple_of:30', 'gt:startMin'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $userId = $this->targetUserId($request);
        if ($request->user()->isTutor()) {
            $tutorId = $request->user()->id;
        } else {
            $tutorId = (int) ($data['tutorId'] ?? 0);
            abort_unless(
                \App\Models\User::where('id', $tutorId)->where('role', 'tutor')->where('student_id', $userId)->exists(),
                422,
                '講師を選択してください。',
            );
        }
        $on = \Carbon\Carbon::parse($data['workOn']);

        $invoice = TutorInvoice::firstOrCreate(
            ['tutor_id' => $tutorId, 'year' => $on->year, 'month' => $on->month],
            [
                'user_id' => $userId,
                'status' => TutorInvoice::STATUS_OPEN,
                // 時給は講師アカウント（システム設定）の値。締め時にスナップショットされる
                'hourly_rate' => (int) (\App\Models\User::where('id', $tutorId)->value('hourly_rate') ?? 0),
            ],
        );
        abort_unless($invoice->user_id === $userId, 403);
        abort_unless($invoice->status === TutorInvoice::STATUS_OPEN, 422, '締め済みの月には稼働時間を追加できません。締め解除してから登録してください。');

        $invoice->entries()->create([
            'work_on' => $on->toDateString(),
            'start_min' => $data['startMin'],
            'end_min' => $data['endMin'],
            'note' => $data['note'] ?? null,
        ]);
        $invoice->load(['tutor:id,name,hourly_rate', 'user:id,name', 'entries']);

        return response()->json(['data' => $this->payload($invoice, true)], 201);
    }

    public function destroyEntry(Request $request, TutorWorkEntry $entry): JsonResponse
    {
        $invoice = $entry->invoice;
        abort_if($invoice === null, 404);
        $this->authorizeInvoice($request, $invoice);
        abort_unless($invoice->status === TutorInvoice::STATUS_OPEN, 422, '締め済みの月の稼働時間は削除できません。');
        $entry->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** 時給・メモの更新（生徒・講師とも可。仮発行前まで） */
    public function update(Request $request, TutorInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        abort_unless(in_array($invoice->status, [TutorInvoice::STATUS_OPEN, TutorInvoice::STATUS_CLOSED], true), 422, '仮発行後は変更できません。');
        $data = $request->validate([
            'hourlyRate' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $payload = [];
        if (array_key_exists('hourlyRate', $data)) {
            $payload['hourly_rate'] = $data['hourlyRate'];
        }
        if (array_key_exists('note', $data)) {
            $payload['note'] = $data['note'];
        }
        if ($payload !== []) {
            $invoice->update($payload);
        }
        $invoice->load(['tutor:id,name,hourly_rate', 'user:id,name', 'entries']);

        return response()->json(['data' => $this->payload($invoice, true)]);
    }

    // ====================== ステータスフロー ======================

    /** 締め（講師）: open → closed。生徒が仮発行できるようになる */
    public function close(Request $request, TutorInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        abort_if($invoice->entries()->count() === 0, 422, '稼働時間が登録されていません。');
        // 締め時点の講師の時給（システム設定）をスナップショットする
        $rate = (int) (\App\Models\User::where('id', $invoice->tutor_id)->value('hourly_rate') ?? 0);
        $this->transition($invoice, TutorInvoice::STATUS_OPEN, TutorInvoice::STATUS_CLOSED, ['closed_at' => now(), 'hourly_rate' => $rate]);

        return $this->show($request, $invoice->fresh());
    }

    /** 締め解除（講師）: closed → open */
    public function reopen(Request $request, TutorInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $this->transition($invoice, TutorInvoice::STATUS_CLOSED, TutorInvoice::STATUS_OPEN, ['closed_at' => null]);

        return $this->show($request, $invoice->fresh());
    }

    /** 仮発行（生徒）: closed → issued。①講師へ LINE 通知 */
    public function issue(Request $request, TutorInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $this->transition($invoice, TutorInvoice::STATUS_CLOSED, TutorInvoice::STATUS_ISSUED, ['issued_at' => now()]);
        LineNotify::push(
            $invoice->tutor,
            "【受験ナビ】{$invoice->year}年{$invoice->month}月分の請求書が仮発行されました（{$this->amountText($invoice)}）。\n内容を確認し、問題なければ「請求内容確認済み」に更新してください。\n".config('app.url'),
        );

        return $this->show($request, $invoice->fresh());
    }

    /** 請求内容確認済み＝正式発行（講師）: issued → confirmed。②生徒へ LINE 通知 */
    public function confirm(Request $request, TutorInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $this->transition($invoice, TutorInvoice::STATUS_ISSUED, TutorInvoice::STATUS_CONFIRMED, ['confirmed_at' => now()]);
        LineNotify::push(
            $invoice->user,
            "【受験ナビ】{$invoice->year}年{$invoice->month}月分の請求書の内容が確認され、正式に発行されました（{$this->amountText($invoice)}）。\nお支払い後、「支払済み」に更新してください。\n".config('app.url'),
        );

        return $this->show($request, $invoice->fresh());
    }

    /** 支払済み（生徒）: confirmed → paid。③講師へ LINE 通知 */
    public function pay(Request $request, TutorInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $this->transition($invoice, TutorInvoice::STATUS_CONFIRMED, TutorInvoice::STATUS_PAID, ['paid_at' => now()]);
        LineNotify::push(
            $invoice->tutor,
            "【受験ナビ】{$invoice->year}年{$invoice->month}月分の請求書が支払済みに更新されました（{$this->amountText($invoice)}）。\n入金を確認し、「支払確認済み」に更新してください。\n".config('app.url'),
        );

        return $this->show($request, $invoice->fresh());
    }

    /** 支払確認済み（講師）: paid → done。④生徒へ LINE 通知 */
    public function confirmPayment(Request $request, TutorInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $this->transition($invoice, TutorInvoice::STATUS_PAID, TutorInvoice::STATUS_DONE, ['done_at' => now()]);
        LineNotify::push(
            $invoice->user,
            "【受験ナビ】{$invoice->year}年{$invoice->month}月分の請求書の支払いが確認されました。ありがとうございました。\n".config('app.url'),
        );

        return $this->show($request, $invoice->fresh());
    }

    // ====================== PDF ======================

    /**
     * 請求書 PDF。画面側で描画した請求書画像（A4）を受け取り、1ページの PDF にして返す。
     * （サーバー側 FPDF は日本語フォントを持たないため、日本語の描画はフロントの canvas で行う）
     */
    public function pdf(Request $request, TutorInvoice $invoice): BinaryFileResponse
    {
        $this->authorizeInvoice($request, $invoice);
        abort_if($invoice->status === TutorInvoice::STATUS_OPEN, 422, '締め前の請求書は PDF 発行できません。');
        $request->validate(['image' => ['required', 'file', 'max:25600']]);

        $disk = Storage::disk('local');
        $jpgRel = 'invoices/'.$invoice->id.'/invoice.jpg';
        $pdfRel = 'invoices/'.$invoice->id.'/invoice.pdf';
        $disk->put($jpgRel, ImageTools::normalizeJpeg((string) file_get_contents($request->file('image')->getRealPath()), 2600, 90));
        PdfTools::imagesToPdf([['path' => $disk->path($jpgRel)]], $disk->path($pdfRel));

        $name = sprintf('請求書_%d年%02d月_%s.pdf', $invoice->year, $invoice->month, $invoice->tutor?->name ?? '');

        return response()->file($disk->path($pdfRel), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', str_replace(['/', '\\', '%', '"'], '-', $name), 'invoice-'.$invoice->id.'.pdf'),
        ]);
    }

    // ====================== 内部処理 ======================

    private function transition(TutorInvoice $invoice, string $from, string $to, array $extra): void
    {
        abort_unless($invoice->status === $from, 422, '現在のステータスではこの操作はできません。画面を再読み込みしてください。');
        $invoice->update(['status' => $to, ...$extra]);
    }

    private function amountText(TutorInvoice $invoice): string
    {
        $minutes = (int) $invoice->entries()->get()->sum(fn (TutorWorkEntry $e) => $e->end_min - $e->start_min);
        $amount = (int) round($minutes * $invoice->hourly_rate / 60);
        $hours = rtrim(rtrim(number_format($minutes / 60, 1), '0'), '.');

        return "{$hours}時間・".number_format($amount).'円';
    }

    private function payload(TutorInvoice $i, bool $withEntries = false): array
    {
        $minutes = (int) $i->entries->sum(fn (TutorWorkEntry $e) => $e->end_min - $e->start_min);
        // 締め前は講師アカウント（システム設定）の現在の時給、締め後はスナップショットを使う
        $rate = $i->status === TutorInvoice::STATUS_OPEN
            ? (int) ($i->tutor?->hourly_rate ?? $i->hourly_rate)
            : $i->hourly_rate;
        $out = [
            'id' => $i->id,
            'year' => $i->year,
            'month' => $i->month,
            'tutorId' => $i->tutor_id,
            'tutorName' => $i->tutor?->name,
            'studentName' => $i->user?->name,
            'hourlyRate' => $rate,
            'status' => $i->status,
            'note' => $i->note,
            'entryCount' => $i->entries->count(),
            'totalMinutes' => $minutes,
            'amount' => (int) round($minutes * $rate / 60),
            'closedAt' => $i->closed_at?->toDateTimeString(),
            'issuedAt' => $i->issued_at?->toDateTimeString(),
            'confirmedAt' => $i->confirmed_at?->toDateTimeString(),
            'paidAt' => $i->paid_at?->toDateTimeString(),
            'doneAt' => $i->done_at?->toDateTimeString(),
        ];
        if ($withEntries) {
            $out['entries'] = $i->entries->map(fn (TutorWorkEntry $e) => [
                'id' => $e->id,
                'workOn' => $e->work_on->toDateString(),
                'startMin' => $e->start_min,
                'endMin' => $e->end_min,
                'minutes' => $e->end_min - $e->start_min,
                'note' => $e->note,
            ])->values();
        }

        return $out;
    }

    private function authorizeInvoice(Request $request, TutorInvoice $invoice): void
    {
        abort_unless($invoice->user_id === $this->targetUserId($request), 403);
        if ($request->user()->isTutor()) {
            abort_unless($invoice->tutor_id === $request->user()->id, 403);
        }
    }
}
