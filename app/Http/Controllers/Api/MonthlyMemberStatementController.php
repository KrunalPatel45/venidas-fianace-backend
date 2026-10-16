<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FundEntry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyMemberStatementController extends Controller
{
    private function options(Request $request): array
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'lang' => ['nullable', 'in:gu,en'],
        ]);

        $month = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $data['month'] . '-01'
        );

        if (!$month || $month->format('Y-m') !== $data['month']) {
            throw ValidationException::withMessages([
                'month' => 'Invalid month.',
            ]);
        }

        if ($month->greaterThan(CarbonImmutable::today()->startOfMonth())) {
            throw ValidationException::withMessages([
                'month' => 'Future month is not allowed.',
            ]);
        }

        return [
            'month' => $data['month'],
            'start' => $month->format('Y-m-d'),
            'end' => $month->endOfMonth()->format('Y-m-d'),
            'lang' => $data['lang'] ?? 'gu',
        ];
    }

    private function paise($value): int
    {
        return Money::toPaise((string) ($value ?? '0'));
    }

    private function rupees(int $value): string
    {
        return $value < 0
            ? '-' . Money::fromPaise(abs($value))
            : Money::fromPaise($value);
    }

    /**
     * Allocate historical interest month by month.
     *
     * Each month's collected interest is distributed using
     * investments paid up to the end of that month.
     *
     * Returns cumulative collected interest in paise.
     */
    private function allocateCumulativeEarnings(
        array &$members,
        array $accountOwners,
        string $end
    ): int {
        if ($accountOwners === []) {
            $hasInterest = FundEntry::query()
                ->where('reference_type', 'loan_interest')
                ->where('direction', 'credit')
                ->whereDate('transaction_date', '<=', $end)
                ->exists();

            if ($hasInterest) {
                throw ValidationException::withMessages([
                    'month' => 'Interest exists but there are no eligible accounts.',
                ]);
            }

            return 0;
        }

        $investmentRows = DB::table('monthly_investments')
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereDate('paid_at', '<=', $end)
            ->whereIn(
                'society_account_id',
                array_keys($accountOwners)
            )
            ->select(
                'society_account_id',
                'paid_at',
                'amount'
            )
            ->orderBy('paid_at')
            ->get();

        $interestRows = FundEntry::query()
            ->where('reference_type', 'loan_interest')
            ->where('direction', 'credit')
            ->whereDate('transaction_date', '<=', $end)
            ->get([
                'transaction_date',
                'amount'
            ]);

        $investmentByMonth = [];

        foreach ($investmentRows as $investment) {
            $userId = $accountOwners[
                (int) $investment->society_account_id
            ];

            $month = substr(
                (string) $investment->paid_at,
                0,
                7
            );

            $investmentByMonth[$month][$userId] =
                ($investmentByMonth[$month][$userId] ?? 0)
                + $this->paise($investment->amount);
        }

        $interestByMonth = [];

        foreach ($interestRows as $entry) {
            $month = substr(
                (string) $entry->transaction_date,
                0,
                7
            );

            $interestByMonth[$month] =
                ($interestByMonth[$month] ?? 0)
                + $this->paise($entry->amount);
        }

        $months = array_unique(array_merge(
            array_keys($investmentByMonth),
            array_keys($interestByMonth)
        ));

        sort($months);

        if ($months === []) {
            return 0;
        }

        $runningInvestment = array_fill_keys(
            array_keys($members),
            0
        );

        $cumulativeInterest = 0;

        $lastMonth = substr($end, 0, 7);

        $cursor = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $months[0] . '-01'
        );

        $last = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $lastMonth . '-01'
        );

        while ($cursor->lessThanOrEqualTo($last)) {
            $month = $cursor->format('Y-m');

            foreach (
                $investmentByMonth[$month] ?? []
                as $userId => $amount
            ) {
                $runningInvestment[$userId] += $amount;
            }

            $pool = $interestByMonth[$month] ?? 0;

            if ($pool < 0) {
                throw new \RuntimeException(
                    'Negative interest pool: ' . $month
                );
            }

            if ($pool > 0) {
                $denominator = array_sum(
                    $runningInvestment
                );

                if ($denominator <= 0) {
                    throw ValidationException::withMessages([
                        'month' =>
                            'Interest collected without paid investment in '
                            . $month,
                    ]);
                }

                $allocated = 0;
                $remainders = [];

                foreach (
                    $runningInvestment as $userId => $invested
                ) {
                    if ($invested <= 0) {
                        continue;
                    }

                    $product = $invested * $pool;

                    $share = intdiv(
                        $product,
                        $denominator
                    );

                    $members[$userId]['earning_paise'] +=
                        $share;

                    $allocated += $share;

                    $remainders[] = [
                        'user_id' => $userId,
                        'remainder' =>
                            $product % $denominator,
                    ];
                }

                usort(
                    $remainders,
                    fn ($a, $b) =>
                        ($b['remainder'] <=> $a['remainder'])
                        ?: ($a['user_id'] <=> $b['user_id'])
                );

                $remaining = $pool - $allocated;

                for ($i = 0; $i < $remaining; $i++) {
                    $userId = $remainders[$i]['user_id'];

                    $members[$userId]['earning_paise']++;
                }

                $cumulativeInterest += $pool;
            }

            $cursor = $cursor->addMonth();
        }

        return $cumulativeInterest;
    }

    private function build(array $options): array
    {
        $start = $options['start'];
        $end = $options['end'];

        $accounts = DB::table('society_accounts as a')
            ->join(
                'users as u',
                'u.id',
                '=',
                'a.user_id'
            )
            ->whereDate('a.opened_at', '<=', $end)
            ->select(
                'a.id',
                'a.user_id',
                'a.monthly_amount',
                'u.name',
                'u.name_gu',
                'u.member_code'
            )
            ->orderBy('a.id')
            ->get();

        $members = [];
        $accountOwners = [];

        foreach ($accounts as $account) {
            $userId = (int) $account->user_id;
            $accountId = (int) $account->id;

            if (!isset($members[$userId])) {
                $members[$userId] = [
                    'user_id' => $userId,
                    'name' => $account->name,
                    'name_gu' => $account->name_gu,
                    'member_code' => $account->member_code,
                    'account_count' => 0,
                    'monthly_paise' => 0,
                    'investment_paise' => 0,
                    'loan_paise' => 0,
                    'interest_paise' => 0,
                    'earning_paise' => 0,
                ];
            }

            $members[$userId]['account_count']++;

            $members[$userId]['monthly_paise'] +=
                $this->paise(
                    $account->monthly_amount
                );

            $accountOwners[$accountId] = $userId;
        }

        // Investments paid up to selected month end.
        $investments = DB::table('monthly_investments')
            ->where('status', 'paid')
            ->whereDate('paid_at', '<=', $end)
            ->select('society_account_id')
            ->selectRaw('SUM(amount) AS total_amount')
            ->groupBy('society_account_id')
            ->get();

        foreach ($investments as $investment) {
            $accountId =
                (int) $investment->society_account_id;

            $userId =
                $accountOwners[$accountId] ?? null;

            if ($userId !== null) {
                $members[$userId]['investment_paise'] +=
                    $this->paise(
                        $investment->total_amount
                    );
            }
        }

        // Outstanding member loan principal.
        $loans = DB::table('loans')
            ->where('loan_type', 'member')
            ->whereDate('disbursed_at', '<=', $end)
            ->select(
                'id',
                'borrower_id',
                'principal_amount'
            )
            ->get();

        $loanOwners = [];
        $loanBalances = [];

        foreach ($loans as $loan) {
            $loanId = (int) $loan->id;

            $loanOwners[$loanId] =
                (int) $loan->borrower_id;

            $loanBalances[$loanId] =
                $this->paise(
                    $loan->principal_amount
                );
        }

        if (count($loanBalances) > 0) {
            $repayments = DB::table(
                'loan_principal_repayments'
            )
                ->whereIn(
                    'loan_id',
                    array_keys($loanBalances)
                )
                ->whereDate('paid_at', '<=', $end)
                ->select('loan_id')
                ->selectRaw(
                    'SUM(amount) AS total_amount'
                )
                ->groupBy('loan_id')
                ->get();

            foreach ($repayments as $repayment) {
                $loanId = (int) $repayment->loan_id;

                $loanBalances[$loanId] -=
                    $this->paise(
                        $repayment->total_amount
                    );
            }
        }

        foreach ($loanBalances as $loanId => $balance) {
            if ($balance < 0) {
                throw new \RuntimeException(
                    'Loan repayment exceeds principal. Loan ID: '
                    . $loanId
                );
            }

            $userId = $loanOwners[$loanId];

            if (isset($members[$userId])) {
                $members[$userId]['loan_paise'] +=
                    $balance;
            }
        }

        // Interest collected only in selected month.
        $interestEntries = FundEntry::query()
            ->where(
                'reference_type',
                'loan_interest'
            )
            ->where('direction', 'credit')
            ->whereDate(
                'transaction_date',
                '>=',
                $start
            )
            ->whereDate(
                'transaction_date',
                '<=',
                $end
            )
            ->get([
                'reference_id',
                'amount'
            ]);

        $interestPool = 0;
        $interestByDue = [];

        foreach ($interestEntries as $entry) {
            $amount = $this->paise(
                $entry->amount
            );

            $interestPool += $amount;

            $dueId = (int) $entry->reference_id;

            $interestByDue[$dueId] =
                ($interestByDue[$dueId] ?? 0)
                + $amount;
        }

        // Member borrowers' interest payments.
        if (count($interestByDue) > 0) {
            $dues = DB::table(
                'loan_interest_dues as d'
            )
                ->join(
                    'loans as l',
                    'l.id',
                    '=',
                    'd.loan_id'
                )
                ->whereIn(
                    'd.id',
                    array_keys($interestByDue)
                )
                ->where(
                    'l.loan_type',
                    'member'
                )
                ->select(
                    'd.id',
                    'l.borrower_id'
                )
                ->get();

            foreach ($dues as $due) {
                $userId =
                    (int) $due->borrower_id;

                if (isset($members[$userId])) {
                    $members[$userId]['interest_paise'] +=
                        $interestByDue[
                            (int) $due->id
                        ];
                }
            }
        }

        $totalInvestment = array_sum(
            array_column(
                $members,
                'investment_paise'
            )
        );

        // Calculate cumulative earnings month by month.
        $cumulativeInterestPool =
            $this->allocateCumulativeEarnings(
                $members,
                $accountOwners,
                $end
            );

        $rows = [];

        $totalMonthly = 0;
        $totalLoans = 0;
        $totalMemberInterest = 0;
        $totalEarnings = 0;

        foreach ($members as $member) {
            $totalMonthly +=
                $member['monthly_paise'];

            $totalLoans +=
                $member['loan_paise'];

            $totalMemberInterest +=
                $member['interest_paise'];

            $totalEarnings +=
                $member['earning_paise'];

            $rows[] = [
                'user_id' =>
                    $member['user_id'],

                'member_code' =>
                    $member['member_code'],

                'name' =>
                    $member['name'],

                'name_gu' =>
                    $member['name_gu'],

                'account_count' =>
                    $member['account_count'],

                'monthly_amount' => $this->rupees(
                    $member['monthly_paise']
                ),

                'total_investment' => $this->rupees(
                    $member['investment_paise']
                ),

                'loan_amount' => $this->rupees(
                    $member['loan_paise']
                ),

                'interest_paid' => $this->rupees(
                    $member['interest_paise']
                ),

                'total_amount' => $this->rupees(
                    $member['investment_paise']
                    + $member['interest_paise']
                ),

                'distributed_earning' => $this->rupees(
                    $member['earning_paise']
                ),

                'investment_with_earning' => $this->rupees(
                    $member['investment_paise']
                    + $member['earning_paise']
                ),
            ];
        }

        usort(
            $rows,
            fn ($a, $b) =>
                (
                    $this->paise(
                        $b['total_investment']
                    )
                    <=>
                    $this->paise(
                        $a['total_investment']
                    )
                )
                ?: (
                    $a['user_id']
                    <=>
                    $b['user_id']
                )
        );

        foreach ($rows as $index => &$row) {
            $row['serial_number'] =
                $index + 1;
        }

        unset($row);

        // Cumulative allocated earnings must match
        // cumulative collected interest.
        if ($totalEarnings !== $cumulativeInterestPool) {
            throw new \RuntimeException(
                'Earnings allocation reconciliation failed.'
            );
        }

        return [
            'month' => $options['month'],
            'language' => $options['lang'],
            'rows' => $rows,

            'summary' => [
                'member_count' =>
                    count($rows),

                'account_count' =>
                    count($accounts),

                'monthly_amount' => $this->rupees(
                    $totalMonthly
                ),

                'total_investment' => $this->rupees(
                    $totalInvestment
                ),

                'loan_amount' => $this->rupees(
                    $totalLoans
                ),

                'interest_paid_by_members' => $this->rupees(
                    $totalMemberInterest
                ),

                'total_amount' => $this->rupees(
                    $totalInvestment
                    + $totalMemberInterest
                ),

                // Selected month interest.
                'collected_interest' => $this->rupees(
                    $interestPool
                ),

                // Overall interest up to selected month.
                'cumulative_collected_interest' =>
                    $this->rupees(
                        $cumulativeInterestPool
                    ),

                // Overall distributed earnings.
                'distributed_earning' => $this->rupees(
                    $totalEarnings
                ),

                // Overall investment + earnings.
                'investment_with_earning' =>
                    $this->rupees(
                        $totalInvestment
                        + $totalEarnings
                    ),
            ],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $options = $this->options($request);

        return response()->json([
            'success' => true,
            'data' => $this->build($options),
        ]);
    }
}