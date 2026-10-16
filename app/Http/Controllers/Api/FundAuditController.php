<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FundEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FundAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'to_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:from_date',
            ],
            'direction' => [
                'nullable',
                'in:credit,debit',
            ],
            'reference_type' => [
                'nullable',
                'string',
                'max:100',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $query = FundEntry::query();

        if (!empty($data['from_date'])) {
            $query->whereDate(
                'transaction_date',
                '>=',
                $data['from_date']
            );
        }

        if (!empty($data['to_date'])) {
            $query->whereDate(
                'transaction_date',
                '<=',
                $data['to_date']
            );
        }

        if (!empty($data['direction'])) {
            $query->where(
                'direction',
                $data['direction']
            );
        }

        if (!empty($data['reference_type'])) {
            $query->where(
                'reference_type',
                $data['reference_type']
            );
        }

        return response()->json([
            'success' => true,
            'data' => $query
                ->orderByDesc('transaction_date')
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20),
        ]);
    }
}
