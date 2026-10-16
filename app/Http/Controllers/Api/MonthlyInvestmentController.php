<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateMonthlyInvestmentRequest;
use App\Http\Requests\PayMonthlyInvestmentRequest;
use App\Models\MonthlyInvestment;
use App\Services\MonthlyInvestmentService;
use App\Services\WhatsAppPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyInvestmentController extends Controller
{
    public function __construct(
        private readonly MonthlyInvestmentService $service
    ) {
    }

    public function generate(
        GenerateMonthlyInvestmentRequest $request
    ): JsonResponse {
        $result = $this->service->generate(
            $request->validated('month')
        );

        return response()->json([
            'success' => true,
            'message' => 'Monthly investment dues generated.',
            'data' => $result,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,paid'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'society_account_id' => [
                'nullable',
                'integer',
                'exists:society_accounts,id',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $query = MonthlyInvestment::query()
            ->with([
                'account:id,user_id,account_number,monthly_amount,status',
                'receivedBy:id,name,name_gu',
            ])
            ->where(
                'investment_month',
                $validated['month'] . '-01'
            );

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['society_account_id'])) {
            $query->where(
                'society_account_id',
                $validated['society_account_id']
            );
        }

        if (!empty($validated['user_id'])) {
            $query->whereIn(
                'society_account_id',
                DB::table('society_accounts')
                    ->select('id')
                    ->where(
                        'user_id',
                        $validated['user_id']
                    )
            );
        }

        $data = $query
            ->orderBy('id')
            ->paginate(
                $validated['per_page'] ?? 20
            );

        $userIds = $data->getCollection()
            ->pluck('account.user_id')
            ->filter()
            ->unique()
            ->values();

        $members = DB::table('users')
            ->whereIn('id', $userIds)
            ->get([
                'id',
                'member_code',
                'name',
                'name_gu',
            ])
            ->keyBy('id');

        $data->getCollection()->transform(
            function ($investment) use ($members) {
                $userId = $investment->account?->user_id;

                $investment->setAttribute(
                    'member',
                    $userId
                        ? ($members->get($userId) ?? null)
                        : null
                );

                return $investment;
            }
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Fetch all pending dues for one member and month.
     */
    public function pendingForMember(
        Request $request
    ): JsonResponse {
        $validated = $request->validate([
            'month' => [
                'required',
                'date_format:Y-m',
            ],
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ]);

        $data = MonthlyInvestment::query()
            ->with([
                'account:id,user_id,account_number,monthly_amount,status',
            ])
            ->where(
                'investment_month',
                $validated['month'] . '-01'
            )
            ->where('status', 'pending')
            ->whereIn(
                'society_account_id',
                DB::table('society_accounts')
                    ->select('id')
                    ->where(
                        'user_id',
                        $validated['user_id']
                    )
            )
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => [
                'required',
                'date_format:Y-m',
            ],
        ]);

        $summary = MonthlyInvestment::query()
            ->where(
                'investment_month',
                $validated['month'] . '-01'
            )
            ->selectRaw(
                'COUNT(*) AS total_accounts'
            )
            ->selectRaw(
                'COALESCE(SUM(amount), 0) AS expected_amount'
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_accounts"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS collected_amount"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_accounts"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount"
            )
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $validated['month'],
                'total_accounts' =>
                    (int) $summary->total_accounts,
                'expected_amount' =>
                    (string) $summary->expected_amount,
                'paid_accounts' =>
                    (int) $summary->paid_accounts,
                'collected_amount' =>
                    (string) $summary->collected_amount,
                'pending_accounts' =>
                    (int) $summary->pending_accounts,
                'pending_amount' =>
                    (string) $summary->pending_amount,
            ],
        ]);
    }

    /**
     * Receive one investment payment.
     *
     * Payment, ledger and WhatsApp notification record
     * are created inside the same database transaction.
     */
    public function pay(
        PayMonthlyInvestmentRequest $request,
        MonthlyInvestment $investment,
        WhatsAppPaymentService $whatsApp
    ): JsonResponse {
        $validated = $request->validated();

        $paid = DB::transaction(function () use (
            $investment,
            $request,
            $validated,
            $whatsApp
        ) {
            $paid = $this->service->receiveCash(
                $investment,
                $request->user()->id,
                $validated['paid_at']
            );

            $whatsApp->investment($paid);

            return $paid;
        }, 3);

        return response()->json([
            'success' => true,
            'message' =>
                'Cash payment received successfully.',
            'data' => $paid->load([
                'account:id,user_id,account_number',
                'receivedBy:id,name,name_gu',
            ]),
        ]);
    }

    /**
     * Receive bulk investment payments.
     *
     * Uses receiveBulkCash() so the shared cash state
     * is locked before individual investment records.
     *
     * Only one combined WhatsApp notification is created.
     */
    public function bulkPay(
        Request $request,
        WhatsAppPaymentService $whatsApp
    ): JsonResponse {
        $validated = $request->validate([
            'investment_ids' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],
            'investment_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:monthly_investments,id',
            ],
            'payment_method' => [
                'required',
                'in:cash',
            ],
            'paid_at' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
        ]);

        $ids = array_map(
            'intval',
            $validated['investment_ids']
        );

        sort($ids, SORT_NUMERIC);

        $result = DB::transaction(function () use (
            $ids,
            $validated,
            $request,
            $whatsApp
        ) {
            $bulkResult = $this->service->receiveBulkCash(
                $ids,
                $request->user()->id,
                $validated['paid_at']
            );

            $paidIds = array_map(
                fn ($investment) => (int) $investment->id,
                $bulkResult['investments']
            );

            // Create one combined notification only.
            // Do not call investment() for each account.
            $whatsApp->bulkInvestment($paidIds);

            return [
                'paid_count' => $bulkResult['count'],
                'investment_ids' => $paidIds,
                'total_amount' =>
                    $bulkResult['total_amount'],
                'member_id' =>
                    $bulkResult['member_id'],
                'payment_method' =>
                    $bulkResult['payment_method'],
                'paid_at' =>
                    $bulkResult['paid_at'],
            ];
        }, 3);

        return response()->json([
            'success' => true,
            'message' =>
                'Bulk cash payment received successfully.',
            'data' => $result,
        ]);
    }

    public function myInvestments(
        Request $request
    ): JsonResponse {
        $validated = $request->validate([
            'month' => [
                'nullable',
                'date_format:Y-m',
            ],
            'status' => [
                'nullable',
                'in:pending,paid',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $accountIds = DB::table('society_accounts')
            ->select('id')
            ->where(
                'user_id',
                $request->user()->id
            );

        $query = MonthlyInvestment::query()
            ->with([
                'account:id,user_id,account_number',
            ])
            ->whereIn(
                'society_account_id',
                $accountIds
            );

        if (!empty($validated['month'])) {
            $query->where(
                'investment_month',
                $validated['month'] . '-01'
            );
        }

        if (!empty($validated['status'])) {
            $query->where(
                'status',
                $validated['status']
            );
        }

        return response()->json([
            'success' => true,
            'data' => $query
                ->orderByDesc('investment_month')
                ->orderBy('id')
                ->paginate(
                    $validated['per_page'] ?? 20
                ),
        ]);
    }
}
