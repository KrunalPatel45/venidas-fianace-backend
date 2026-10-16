<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FundEntry;
use Illuminate\Http\Request;

class FundController extends Controller
{
    public function summary()
    {
        $credits = FundEntry::where('direction', 'credit')
            ->sum('amount');

        $debits = FundEntry::where('direction', 'debit')
            ->sum('amount');

        $openingFees = FundEntry::where(
            'reference_type',
            'opening_fee'
        )->where('direction', 'credit')->sum('amount');

        return response()->json([
            'total_credits' => $credits,
            'total_debits' => $debits,
            'available_fund' => round($credits - $debits, 2),
            'opening_fee_collection' => $openingFees,
        ]);
    }

    public function entries(Request $request)
    {
        return FundEntry::query()
            ->when($request->from, function ($query, $from) {
                $query->whereDate('transaction_date', '>=', $from);
            })
            ->when($request->to, function ($query, $to) {
                $query->whereDate('transaction_date', '<=', $to);
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(30);
    }
}