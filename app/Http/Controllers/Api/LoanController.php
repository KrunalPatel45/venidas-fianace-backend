<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateLoanInterestRequest;
use App\Http\Requests\PayLoanInterestRequest;
use App\Http\Requests\RepayLoanPrincipalRequest;
use App\Http\Requests\StoreLoanRequest;
use App\Models\Loan;
use App\Models\LoanInterestDue;
use App\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\WhatsAppPaymentService;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    public function __construct(
        private readonly LoanService $service
    ) {
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        $loan = $this->service->create(
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Cash loan disbursed successfully.',
            'data' => $loan->load('borrower:id,member_code,name,name_gu'),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'loan_type' => ['nullable', 'in:member,external'],
            'status' => ['nullable', 'in:active,closed'],
            'borrower_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Loan::query()
            ->with('borrower:id,member_code,name,name_gu');

        foreach (['loan_type', 'status', 'borrower_id'] as $key) {
            if (isset($data[$key])) {
                $query->where($key, $data[$key]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $query
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20),
        ]);
    }

    public function show(Loan $loan): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $loan->load([
                'borrower:id,member_code,name,name_gu',
                'interestDues',
                'principalRepayments',
            ]),
        ]);
    }

    public function generateInterest(
        GenerateLoanInterestRequest $request
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $this->service->generateInterest(
                $request->validated('month')
            ),
        ]);
    }

    public function interestList(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,paid'],
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LoanInterestDue::query()
            ->with([
                'loan:id,loan_number,borrower_id,loan_type',
                'loan.borrower:id,member_code,name,name_gu',
            ])
            ->where('interest_month', $data['month'] . '-01');

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (!empty($data['loan_id'])) {
            $query->where('loan_id', $data['loan_id']);
        }

        return response()->json([
            'success' => true,
            'data' => $query
                ->orderBy('id')
                ->paginate($data['per_page'] ?? 20),
        ]);
    }

    
    public function payInterest(
        PayLoanInterestRequest $request,
        LoanInterestDue $interest,
        WhatsAppPaymentService $whatsApp
    ): JsonResponse {
        $paid = DB::transaction(function () use (
            $request,
            $interest,
            $whatsApp
        ) {
            $paid = $this->service->receiveInterest(
                $interest,
                $request->user()->id,
                $request->validated('paid_at')
            );

            $whatsApp->interest($paid);

            return $paid;
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Cash interest received.',
            'data' => $paid,
        ]);
    }


    public function repayPrincipal(
        RepayLoanPrincipalRequest $request,
        Loan $loan
    ): JsonResponse {
        $repayment = $this->service->repayPrincipal(
            $loan,
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Cash principal repayment recorded.',
            'data' => [
                'repayment' => $repayment,
                'loan' => $loan->fresh(),
            ],
        ]);
    }

    public function summary(): JsonResponse
    {
        $loans = Loan::query()
            ->selectRaw('loan_type')
            ->selectRaw('COUNT(*) AS total_loans')
            ->selectRaw(
                'COALESCE(SUM(principal_amount), 0) AS total_disbursed'
            )
            ->selectRaw(
                'COALESCE(SUM(outstanding_principal), 0) AS outstanding'
            )
            ->groupBy('loan_type')
            ->get();

        $interest = LoanInterestDue::query()
            ->selectRaw('status')
            ->selectRaw('COUNT(*) AS total_dues')
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('status')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'loans' => $loans,
                'interest' => $interest,
            ],
        ]);
    }

    public function myLoans(Request $request): JsonResponse
    {
        $loans = Loan::query()
            ->where('borrower_id', $request->user()->id)
            ->with([
                'interestDues',
                'principalRepayments',
            ])
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $loans,
        ]);
    }
}
