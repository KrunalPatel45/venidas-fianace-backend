<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PayOpeningFeeRequest;
use App\Models\AccountOpeningFee;
use App\Services\AccountService;
use Illuminate\Http\Request;

class OpeningFeeController extends Controller
{
    public function __construct(
        private AccountService $accountService
    ) {}

    public function index(Request $request)
    {
        return AccountOpeningFee::query()
            ->with('account.member:id,name,member_code')
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->latest('id')
            ->paginate(20);
    }

    public function pay(
        PayOpeningFeeRequest $request,
        AccountOpeningFee $fee
    ) {
        $payment = $this->accountService->receiveOpeningFee(
            $fee,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'message' => 'Opening fee received successfully.',
            'data' => $payment,
        ]);
    }
}