<?php

namespace App\Services;

use App\Models\FundEntry;
use App\Models\MonthlyInvestment;
use App\Models\SocietyAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MonthlyInvestmentService
{
    public function __construct(
        private readonly SocietyCashService $cashService
    ) {
    }

    public function generate(string $month): array
    {
        $monthDate = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $month . '-01'
        );

        if (!$monthDate || $monthDate->format('Y-m') !== $month) {
            throw ValidationException::withMessages([
                'month' => 'Invalid investment month.',
            ]);
        }

        if ($monthDate->greaterThan(
            CarbonImmutable::now()->startOfMonth()
        )) {
            throw ValidationException::withMessages([
                'month' => 'Future investment months cannot be generated.',
            ]);
        }

        $monthStart = $monthDate->format('Y-m-d');
        $monthEnd = $monthDate->endOfMonth()->format('Y-m-d');

        $created = 0;
        $existing = 0;

        SocietyAccount::query()
            ->where('status', 'active')
            ->whereDate('opened_at', '<=', $monthEnd)
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use (
                $monthStart,
                &$created,
                &$existing
            ) {
                foreach ($accounts as $account) {
                    $result = DB::transaction(function () use (
                        $account,
                        $monthStart
                    ) {
                        $lockedAccount = SocietyAccount::query()
                            ->whereKey($account->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($lockedAccount->status !== 'active') {
                            return 'skipped';
                        }

                        $due = MonthlyInvestment::query()
                            ->where(
                                'society_account_id',
                                $lockedAccount->id
                            )
                            ->where('investment_month', $monthStart)
                            ->first();

                        if ($due) {
                            return 'existing';
                        }

                        if ((float) $lockedAccount->monthly_amount <= 0) {
                            throw new RuntimeException(
                                'Invalid monthly amount for account '
                                . $lockedAccount->account_number
                            );
                        }

                        MonthlyInvestment::create([
                            'society_account_id' => $lockedAccount->id,
                            'investment_month' => $monthStart,
                            'amount' => $lockedAccount->monthly_amount,
                            'status' => 'pending',
                            'payment_method' => null,
                            'paid_at' => null,
                            'received_by' => null,
                            'receipt_number' => null,
                        ]);

                        return 'created';
                    }, 3);

                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'existing') {
                        $existing++;
                    }
                }
            });

        return [
            'month' => $month,
            'created' => $created,
            'already_existing' => $existing,
        ];
    }

    public function receiveCash(
        MonthlyInvestment $investment,
        int $adminId,
        string $paidAt
    ): MonthlyInvestment {
        return DB::transaction(function () use (
            $investment,
            $adminId,
            $paidAt
        ) {
            $this->cashService->lock();

            return $this->processCashPayment(
                $investment->id,
                $adminId,
                $paidAt
            );
        }, 3);
    }

    /**
     * Receive cash for multiple investments belonging
     * to the same member and investment month.
     */
    public function receiveBulkCash(
        array $investmentIds,
        int $adminId,
        string $paidAt
    ): array {
        $ids = array_values(array_unique(
            array_map('intval', $investmentIds)
        ));

        if (count($ids) === 0 || count($ids) > 100) {
            throw ValidationException::withMessages([
                'investment_ids' => 'Select between 1 and 100 investments.',
            ]);
        }

        sort($ids, SORT_NUMERIC);

        return DB::transaction(function () use (
            $ids,
            $adminId,
            $paidAt
        ) {
            // Lock shared cash state first, as in single payment.
            $this->cashService->lock();

            $dues = MonthlyInvestment::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($dues->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'investment_ids' => 'One or more investments do not exist.',
                ]);
            }

            $accountIds = $dues
                ->pluck('society_account_id')
                ->unique()
                ->values();

            $accounts = SocietyAccount::query()
                ->whereIn('id', $accountIds)
                ->get()
                ->keyBy('id');

            $memberIds = $accounts
                ->pluck('user_id')
                ->unique()
                ->values();

            if ($memberIds->count() !== 1) {
                throw ValidationException::withMessages([
                    'investment_ids' =>
                        'All selected accounts must belong to the same member.',
                ]);
            }

            $months = $dues
                ->map(fn ($due) =>
                    CarbonImmutable::parse(
                        $due->investment_month
                    )->format('Y-m')
                )
                ->unique();

            if ($months->count() !== 1) {
                throw ValidationException::withMessages([
                    'investment_ids' =>
                        'All investments must belong to the same month.',
                ]);
            }

            $paid = [];
            $totalAmount = '0.00';

            foreach ($dues as $due) {
                $result = $this->processCashPayment(
                    $due->id,
                    $adminId,
                    $paidAt
                );

                $paid[] = $result;

                // Money calculations use decimal strings.
                $totalAmount = bcadd(
                    $totalAmount,
                    (string) $result->amount,
                    2
                );
            }

            return [
                'count' => count($paid),
                'total_amount' => $totalAmount,
                'payment_method' => 'cash',
                'paid_at' => $paidAt,
                'member_id' => $memberIds->first(),
                'investments' => $paid,
            ];
        }, 3);
    }

    /**
     * Called inside a transaction with the cash state locked.
     * Each investment is locked and gets its own ledger entry.
     */
    private function processCashPayment(
        int $investmentId,
        int $adminId,
        string $paidAt
    ): MonthlyInvestment {
        $due = MonthlyInvestment::query()
            ->whereKey($investmentId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($due->status === 'paid') {
            throw ValidationException::withMessages([
                'investment_ids' =>
                    "Investment {$due->id} is already paid.",
            ]);
        }

        if ($due->status !== 'pending') {
            throw ValidationException::withMessages([
                'investment_ids' =>
                    "Investment {$due->id} has an invalid status.",
            ]);
        }

        $account = SocietyAccount::query()
            ->whereKey($due->society_account_id)
            ->firstOrFail();

        if ($account->status !== 'active') {
            throw ValidationException::withMessages([
                'investment_ids' =>
                    "Account {$account->account_number} is not active.",
            ]);
        }

        $paymentDate = CarbonImmutable::parse($paidAt);
        $investmentMonth = CarbonImmutable::parse(
            $due->investment_month
        );

        if ($paymentDate->lessThan($investmentMonth)) {
            throw ValidationException::withMessages([
                'paid_at' =>
                    'Payment date cannot be before the investment month.',
            ]);
        }

        if ($paymentDate->greaterThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages([
                'paid_at' => 'Future payment dates are not allowed.',
            ]);
        }

        $existingLedger = FundEntry::query()
            ->where('reference_type', 'monthly_investment')
            ->where('reference_id', $due->id)
            ->first();

        if ($existingLedger) {
            throw new RuntimeException(
                'A fund entry already exists for this pending investment. '
                . 'Manual reconciliation is required.'
            );
        }

        $receiptNumber = sprintf(
            'VF-MI-%08d',
            $due->id
        );

        $due->update([
            'status' => 'paid',
            'payment_method' => 'cash',
            'paid_at' => $paymentDate->format('Y-m-d'),
            'received_by' => $adminId,
            'receipt_number' => $receiptNumber,
        ]);

        FundEntry::create([
            'reference_type' => 'monthly_investment',
            'reference_id' => $due->id,
            'direction' => 'credit',
            'amount' => $due->amount,
            'transaction_date' => $paymentDate->format('Y-m-d'),
            'description' => 'Monthly cash investment - '
                . $account->account_number
                . ' - '
                . $investmentMonth->format('Y-m'),
            'created_by' => $adminId,
        ]);

        return $due->fresh();
    }
}
