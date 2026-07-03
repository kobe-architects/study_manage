<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = CalendarEvent::where('user_id', $request->user()->id)
            ->orderBy('date')
            ->get(['id', 'date', 'title']);

        $data = $events->map(fn ($e) => [
            'id' => $e->id,
            'date' => $e->date->toDateString(),
            'title' => $e->title,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * 日付ごとに1件として upsert する（同じ日付なら上書き）。
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:255'],
        ]);

        $event = CalendarEvent::updateOrCreate(
            ['user_id' => $userId, 'date' => $data['date']],
            ['title' => $data['title']]
        );

        return response()->json(['data' => [
            'id' => $event->id,
            'date' => $event->date->toDateString(),
            'title' => $event->title,
        ]]);
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        abort_unless($calendarEvent->user_id === $request->user()->id, 403);
        $calendarEvent->delete();

        return response()->json(['message' => 'deleted']);
    }
}
