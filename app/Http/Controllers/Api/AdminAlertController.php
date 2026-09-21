<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StaffAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => app(StaffAlertService::class)->present($user),
        ]);
    }
}
