<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = CalendarEvent::where('user_id', $this->targetUserId($request))
            ->orderBy('date')
            ->get(['id', 'date', 'title', 'is_mock']);

        $data = $events->map(fn ($e) => [
            'id' => $e->id,
            'date' => $e->date->toDateString(),
            'title' => $e->title,
            'isMock' => (bool) $e->is_mock,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * 日付ごとに1件として upsert する（同じ日付なら上書き）。
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:255'],
            'isMock' => ['nullable', 'boolean'],
        ]);

        $event = CalendarEvent::updateOrCreate(
            ['user_id' => $userId, 'date' => $data['date']],
            ['title' => $data['title'], 'is_mock' => (bool) ($data['isMock'] ?? false)]
        );

        return response()->json(['data' => [
            'id' => $event->id,
            'date' => $event->date->toDateString(),
            'title' => $event->title,
            'isMock' => (bool) $event->is_mock,
        ]]);
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        abort_unless($calendarEvent->user_id === $this->targetUserId($request), 403);
        $calendarEvent->delete();

        return response()->json(['message' => 'deleted']);
    }
}
