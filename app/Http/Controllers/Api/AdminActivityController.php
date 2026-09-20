<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(10, (int) $request->integer('per_page', 20)));

        $query = ActivityLog::query()
            ->with('user')
            ->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Journal récupéré avec succès',
            'data' => [
                'entries' => collect($page->items())->map(fn (ActivityLog $log) => $log->toPayload())->values(),
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
                'action_options' => collect(ActivityLog::actionLabels())
                    ->map(fn (string $label, string $name) => [
                        'name' => $name,
                        'label' => $label,
                    ])
                    ->values(),
            ],
        ]);
    }
}
