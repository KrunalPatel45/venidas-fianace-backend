<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $reports
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reports->adminDashboard(),
        ]);
    }

    public function monthly(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->reports->monthly($data['month']),
        ]);
    }
}
