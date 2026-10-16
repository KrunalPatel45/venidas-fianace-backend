<?php

namespace App\Services;

use App\Models\FundEntry;
use App\Models\Loan;
use App\Models\LoanInterestDue;
use App\Models\MonthlyInvestment;
use App\Models\SocietyAccount;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceReportService
{
    private function amount($value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function sumPaise($query, string $column = 'amount'): int
    {
        $total = 0;

        $query->select(['id', $column])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$total, $column) {
                foreach ($rows as $row) {
                    $total += Money::toPaise(
                        (string) $row->{$column}
                    );
                }
            });

        return $total;
    }

    public function adminDashboard(): array
    {
        $cash = app(SocietyCashService::class)->balance();

        return [
            'members' => User::where('role', 'member')->count(),
            'external_borrowers' => User::where('role', 'external')->count(),
            'total_accounts' => SocietyAccount::count(),
            'active_accounts' => SocietyAccount::where(
                'status', 'active'
            )->count(),
            'active_loans' => Loan::where('status', 'active')->count(),
            'outstanding_principal' => Money::fromPaise(
                $this->sumPaise(
                    Loan::query(),
                    'outstanding_principal'
                )
            ),
            'pending_investments' => Money::fromPaise(
                $this->sumPaise(
                    MonthlyInvestment::where('status', 'pending')
                )
            ),
            'pending_loan_interest' => Money::fromPaise(
                $this->sumPaise(
                    LoanInterestDue::where('status', 'pending')
                )
            ),
            'cash_balance' => $cash['balance'],
            'currency' => 'INR',
        ];
    }

    public function memberDashboard(User $user): array
    {
        $accountIds = SocietyAccount::query()
            ->where('user_id', $user->id)
            ->pluck('id');

        $memberLoanIds = Loan::query()
            ->where('borrower_id', $user->id)
            ->pluck('id');

        // Member's total paid monthly investments.
        $memberInvestmentPaise = $this->sumPaise(
            MonthlyInvestment::query()
                ->whereIn('society_account_id', $accountIds)
                ->where('status', 'paid')
        );

        // All members' total paid monthly investments.
        $societyInvestmentPaise = $this->sumPaise(
            MonthlyInvestment::query()
                ->where('status', 'paid')
        );

        // Interest actually received by society.
        $societyInterestPaise = $this->sumPaise(
            FundEntry::query()
                ->where('reference_type', 'loan_interest')
                ->where('direction', 'credit')
        );

        // Estimated proportional interest earnings.
        // Dashboard only: no database writes.
        $memberInterestPaise = 0;

        if ($societyInvestmentPaise > 0) {
            $memberInterestPaise = intdiv(
                ($societyInterestPaise * $memberInvestmentPaise)
                    + intdiv($societyInvestmentPaise, 2),
                $societyInvestmentPaise
            );
        }

        $totalEarningsPaise = $memberInterestPaise;

        $investmentWithEarningsPaise =
            $memberInvestmentPaise + $totalEarningsPaise;

        // Interest paid by this member on their own loans.
        $interestPaidPaise = $this->sumPaise(
            LoanInterestDue::query()
                ->whereIn('loan_id', $memberLoanIds)
                ->where('status', 'paid')
        );

        return [
            'member' => [
                'id' => $user->id,
                'member_code' => $user->member_code,
                'name' => $user->name,
                'name_gu' => $user->name_gu,
            ],

            'accounts' => $accountIds->count(),

            'total_investment' => Money::fromPaise(
                $memberInvestmentPaise
            ),

            'estimated_interest_earned' => Money::fromPaise(
                $memberInterestPaise
            ),

            'total_earnings' => Money::fromPaise(
                $totalEarningsPaise
            ),

            'investment_with_earnings' => Money::fromPaise(
                $investmentWithEarningsPaise
            ),

            'investment_share_percentage' =>
                $societyInvestmentPaise > 0
                    ? round(
                        ($memberInvestmentPaise /
                            $societyInvestmentPaise) * 100,
                        4
                    )
                    : 0,

            'earnings_type' => 'estimated',

            // New: Total loan interest paid.
            'total_interest_paid' => Money::fromPaise(
                $interestPaidPaise
            ),

            // Existing member dashboard calculations.
            'pending_investment' => Money::fromPaise(
                $this->sumPaise(
                    MonthlyInvestment::query()
                        ->whereIn('society_account_id', $accountIds)
                        ->where('status', 'pending')
                )
            ),

            'outstanding_principal' => Money::fromPaise(
                $this->sumPaise(
                    Loan::where('borrower_id', $user->id),
                    'outstanding_principal'
                )
            ),

            'pending_interest' => Money::fromPaise(
                $this->sumPaise(
                    LoanInterestDue::query()
                        ->where('status', 'pending')
                        ->whereIn('loan_id', $memberLoanIds)
                )
            ),

            'currency' => 'INR',
        ];
    }

    public function monthly(string $month): array
    {
        $start = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $month . '-01'
        );

        if (!$start || $start->format('Y-m') !== $month) {
            throw ValidationException::withMessages([
                'month' => 'Invalid report month.',
            ]);
        }

        $from = $start->format('Y-m-d');
        $to = $start->endOfMonth()->format('Y-m-d');

        $dues = MonthlyInvestment::query()
            ->where('investment_month', $from);

        $expectedInvestment = $this->sumPaise(clone $dues);

        $pendingInvestment = $this->sumPaise(
            (clone $dues)->where('status', 'pending')
        );

        $expectedInterest = $this->sumPaise(
            LoanInterestDue::where('interest_month', $from)
        );

        $pendingInterest = $this->sumPaise(
            LoanInterestDue::where('interest_month', $from)
                ->where('status', 'pending')
        );

        $cashByType = [];

        FundEntry::query()
            ->whereBetween('transaction_date', [$from, $to])
            ->orderBy('id')
            ->chunkById(500, function ($entries) use (&$cashByType) {
                foreach ($entries as $entry) {
                    $key = $entry->reference_type;
                    $direction = $entry->direction;

                    if (!isset($cashByType[$key])) {
                        $cashByType[$key] = [
                            'credit' => 0,
                            'debit' => 0,
                        ];
                    }

                    $cashByType[$key][$direction] += Money::toPaise(
                        (string) $entry->amount
                    );
                }
            });

        $totalCredit = 0;
        $totalDebit = 0;
        $breakdown = [];

        foreach ($cashByType as $type => $amounts) {
            $totalCredit += $amounts['credit'];
            $totalDebit += $amounts['debit'];

            $breakdown[$type] = [
                'credit' => Money::fromPaise($amounts['credit']),
                'debit' => Money::fromPaise($amounts['debit']),
            ];
        }

        $net = $totalCredit - $totalDebit;

        return [
            'month' => $month,
            'currency' => 'INR',
            'investment' => [
                'expected' => Money::fromPaise($expectedInvestment),
                'pending' => Money::fromPaise($pendingInvestment),
            ],
            'loan_interest' => [
                'expected' => Money::fromPaise($expectedInterest),
                'pending' => Money::fromPaise($pendingInterest),
            ],
            'cash_flow' => [
                'credits' => Money::fromPaise($totalCredit),
                'debits' => Money::fromPaise($totalDebit),
                'net' => ($net < 0 ? '-' : '')
                    . Money::fromPaise(abs($net)),
                'breakdown' => $breakdown,
            ],
        ];
    }
}