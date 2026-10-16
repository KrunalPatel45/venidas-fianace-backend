<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FundEntry;
use App\Models\Loan;
use App\Models\User;
use App\Services\FinanceReportService;
use App\Services\SocietyCashService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SocietyDashboardController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $reports,
        private readonly SocietyCashService $cashService
    ) {
    }

    private function sumPaise(
        $query,
        string $column = 'amount'
    ): int {
        $total = 0;

        $query
            ->select(['id', $column])
            ->orderBy('id')
            ->chunkById(
                500,
                function ($rows) use (&$total, $column) {
                    foreach ($rows as $row) {
                        $total += Money::toPaise(
                            (string) $row->{$column}
                        );
                    }
                }
            );

        return $total;
    }

    public function index(Request $request): JsonResponse
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $overview = $this->reports->adminDashboard();

            $cash = $this->cashService->balance();

            // Actual monthly investment credits.
            $investmentPaise = $this->sumPaise(
                FundEntry::query()
                    ->where('reference_type', 'monthly_investment')
                    ->where('direction', 'credit')
            );

            // Actual opening fee credits.
            $openingFeesPaise = $this->sumPaise(
                FundEntry::query()
                    ->where('reference_type', 'opening_fee')
                    ->where('direction', 'credit')
            );

            // Original principal of member loans.
            $memberLoansPaise = $this->sumPaise(
                Loan::query()
                    ->whereIn(
                        'borrower_id',
                        User::query()
                            ->where('role', 'member')
                            ->select('id')
                    ),
                'principal_amount'
            );

            // Original principal of external loans.
            $externalLoansPaise = $this->sumPaise(
                Loan::query()
                    ->whereIn(
                        'borrower_id',
                        User::query()
                            ->where('role', 'external')
                            ->select('id')
                    ),
                'principal_amount'
            );

            // Actual interest credited to society fund.
            $interestReceivedPaise = $this->sumPaise(
                FundEntry::query()
                    ->where('reference_type', 'loan_interest')
                    ->where('direction', 'credit')
            );

            return response()->json([
                'success' => true,
                'message' => 'Society dashboard loaded successfully.',

                'data' => [
                    'society' => [
                        'name' => 'VENIDAS FINANCE',
                    ],

                    'overview' => [
                        'total_accounts' =>
                            $overview['total_accounts'],

                        'active_accounts' =>
                            $overview['active_accounts'],

                        'members' =>
                            $overview['members'],

                        'external_borrowers' =>
                            $overview['external_borrowers'],

                        'active_loans' =>
                            $overview['active_loans'],
                    ],

                    'financial_summary' => [
                        'total_investment' =>
                            Money::fromPaise($investmentPaise),

                        'total_opening_fees' =>
                            Money::fromPaise($openingFeesPaise),

                        'total_member_loans' =>
                            Money::fromPaise($memberLoansPaise),

                        'total_external_loans' =>
                            Money::fromPaise($externalLoansPaise),

                        'total_interest_received' =>
                            Money::fromPaise($interestReceivedPaise),

                        'available_fund' =>
                            $cash['balance'],

                        'outstanding_principal' =>
                            $overview['outstanding_principal'],

                        'pending_investments' =>
                            $overview['pending_investments'],

                        'pending_loan_interest' =>
                            $overview['pending_loan_interest'],
                    ],

                    'currency' => 'INR',
                    'as_of' => now()->toIso8601String(),
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load society dashboard.',
            ], 500);
        }
    }
}
