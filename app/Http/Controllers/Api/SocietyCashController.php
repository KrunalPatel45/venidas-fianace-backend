<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SocietyCashService;
use Illuminate\Http\JsonResponse;

class SocietyCashController extends Controller
{
    public function __construct(
        private readonly SocietyCashService $cashService
    ) {
    }

    public function balance(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->cashService->balance(),
        ]);
    }
}
