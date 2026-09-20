<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopSetting;
use Illuminate\Http\JsonResponse;

class ShopContactController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Coordonnées boutique',
            'data' => ShopSetting::current()->toPublicContact(),
        ]);
    }
}
