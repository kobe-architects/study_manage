<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = CalendarEvent::with('creator:id,name')
            ->where('user_id', $this->targetUserId($request))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $events->map(fn ($e) => $this->payload($e))->values()]);
    }

    /** 予定を追加する（同じ日付に複数登録できる）。登録者は操作したユーザー（生徒本人または講師） */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:255'],
            'isMock' => ['nullable', 'boolean'],
        ]);

        $event = CalendarEvent::create([
            'user_id' => $userId,
            'date' => $data['date'],
            'title' => $data['title'],
            'is_mock' => (bool) ($data['isMock'] ?? false),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($event->load('creator:id,name'))]);
    }

    /** 予定の編集（タイトル・模試フラグ） */
    public function update(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        abort_unless($calendarEvent->user_id === $this->targetUserId($request), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'isMock' => ['nullable', 'boolean'],
        ]);
        $calendarEvent->update(['title' => $data['title'], 'is_mock' => (bool) ($data['isMock'] ?? false)]);

        return response()->json(['data' => $this->payload($calendarEvent->fresh(['creator:id,name']))]);
    }

    private function payload(CalendarEvent $e): array
    {
        return [
            'id' => $e->id,
            'date' => $e->date->toDateString(),
            'title' => $e->title,
            'isMock' => (bool) $e->is_mock,
            'createdByName' => $e->creator?->name,
        ];
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        abort_unless($calendarEvent->user_id === $this->targetUserId($request), 403);
        $calendarEvent->delete();

        return response()->json(['message' => 'deleted']);
    }
}
