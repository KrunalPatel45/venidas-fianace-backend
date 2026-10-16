<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberDashboardController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $reports
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reports->memberDashboard(
                $request->user()
            ),
        ]);
    }
}
