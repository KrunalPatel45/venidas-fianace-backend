<?php

namespace App\Services;

use App\Models\FundEntry;
use App\Models\Loan;
use App\Models\LoanInterestDue;
use App\Models\LoanPrincipalRepayment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use App\Services\SocietyCashService;

class LoanService
{
    public function __construct(
        private readonly SocietyCashService $cashService
    ) {
    }

    
    public function create(array $data, int $adminId): Loan
    {
        return DB::transaction(function () use ($data, $adminId) {

            /*
            * Lock first, then check balance and write ledger.
            * All other fund writers must follow this same order.
            */
            $this->cashService->lock();

            $existing = Loan::query()
                ->where(
                    'idempotency_key',
                    $data['idempotency_key']
                )
                ->first();

            if ($existing) {
                $sameRequest =
                    (int) $existing->borrower_id
                        === (int) $data['borrower_id']
                    && $existing->loan_type === $data['loan_type']
                    && Money::toPaise($existing->principal_amount)
                        === Money::toPaise(
                            (string) $data['principal_amount']
                        )
                    && $existing->disbursed_at->format('Y-m-d')
                        === $data['disbursed_at']
                    && $existing->disbursement_method
                        === $data['disbursement_method']
                    && $existing->purpose
                        === ($data['purpose'] ?? null)
                    && $existing->notes
                        === ($data['notes'] ?? null);

                if (!$sameRequest) {
                    throw ValidationException::withMessages([
                        'idempotency_key' =>
                            'This key was already used for a different loan.',
                    ]);
                }

                $ledgerExists = FundEntry::query()
                    ->where('reference_type', 'loan_disbursement')
                    ->where('reference_id', $existing->id)
                    ->where('direction', 'debit')
                    ->exists();

                if (!$ledgerExists) {
                    throw new RuntimeException(
                        'Loan exists without its disbursement ledger.'
                    );
                }

                return $existing;
            }

            $borrower = User::query()
                ->whereKey($data['borrower_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $borrower->role !== $data['loan_type']
                || !$borrower->is_active
            ) {
                throw ValidationException::withMessages([
                    'borrower_id' =>
                        'Borrower must be active and match the loan type.',
                ]);
            }

            $principalPaise = Money::toPaise(
                (string) $data['principal_amount']
            );

            if ($principalPaise <= 0) {
                throw ValidationException::withMessages([
                    'principal_amount' => 'Invalid principal amount.',
                ]);
            }

            $cashBalance = $this->cashService->balancePaise();

            if ($cashBalance < $principalPaise) {
                throw ValidationException::withMessages([
                    'principal_amount' =>
                        'Insufficient society cash balance.',
                ]);
            }

            $rate = $data['loan_type'] === 'member'
                ? '1.000'
                : '2.000';

            $loan = Loan::create([
                'loan_number' => 'VF-LN-' . strtoupper(
                    Str::random(16)
                ),
                'idempotency_key' => $data['idempotency_key'],
                'borrower_id' => $borrower->id,
                'loan_type' => $data['loan_type'],
                'principal_amount' =>
                    Money::fromPaise($principalPaise),
                'outstanding_principal' =>
                    Money::fromPaise($principalPaise),
                'monthly_interest_rate' => $rate,
                'status' => 'active',
                'disbursed_at' => $data['disbursed_at'],
                'disbursement_method' => 'cash',
                'purpose' => $data['purpose'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $adminId,
            ]);

            FundEntry::create([
                'reference_type' => 'loan_disbursement',
                'reference_id' => $loan->id,
                'direction' => 'debit',
                'amount' => $loan->principal_amount,
                'transaction_date' => $data['disbursed_at'],
                'description' =>
                    'Cash loan disbursement - '
                    . $loan->loan_number,
                'created_by' => $adminId,
            ]);

            return $loan->fresh();
        }, 3);
    }


    /**
     * Generate simple monthly interest based on principal
     * outstanding at the start of the month.
     */
  
    public function generateInterest(string $month): array
    {
        $monthDate = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $month . '-01'
        );

        if (
            !$monthDate
            || $monthDate->format('Y-m') !== $month
            || $monthDate->greaterThan(
                CarbonImmutable::now()->startOfMonth()
            )
        ) {
            throw ValidationException::withMessages([
                'month' => 'Invalid or future interest month.',
            ]);
        }

        $monthStart = $monthDate->format('Y-m-d');
        $created = 0;
        $existing = 0;
        $skipped = 0;

        Loan::query()
            ->whereDate('disbursed_at', '<', $monthStart)
            ->orderBy('id')
            ->chunkById(100, function ($loans) use (
                $monthDate,
                $monthStart,
                &$created,
                &$existing,
                &$skipped
            ) {
                foreach ($loans as $loan) {
                    $result = DB::transaction(function () use (
                        $loan,
                        $monthDate,
                        $monthStart
                    ) {
                        $lockedLoan = Loan::query()
                            ->whereKey($loan->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        $existingDue = LoanInterestDue::query()
                            ->where('loan_id', $lockedLoan->id)
                            ->where('interest_month', $monthStart)
                            ->first();

                        if ($existingDue) {
                            return 'existing';
                        }

                        $disbursed = CarbonImmutable::parse(
                            $lockedLoan->disbursed_at
                        );

                        $firstInterestMonth = $disbursed
                            ->startOfMonth()
                            ->addMonth();

                        if ($monthDate->lessThan($firstInterestMonth)) {
                            return 'skipped';
                        }

                        /*
                        * Generate months in sequence.
                        * Avoid silently skipping historical months.
                        */
                        $previousMonth = $monthDate->subMonth();

                        if (
                            !$previousMonth->lessThan($firstInterestMonth)
                        ) {
                            $previousExists = LoanInterestDue::query()
                                ->where('loan_id', $lockedLoan->id)
                                ->where(
                                    'interest_month',
                                    $previousMonth->format('Y-m-d')
                                )
                                ->exists();

                            if (!$previousExists) {
                                throw ValidationException::withMessages([
                                    'month' =>
                                        'Generate previous loan interest months first. '
                                        . 'Loan: '
                                        . $lockedLoan->loan_number,
                                ]);
                            }
                        }

                        $principalPaise = Money::toPaise(
                            (string) $lockedLoan->principal_amount
                        );

                        $repayments = LoanPrincipalRepayment::query()
                            ->where('loan_id', $lockedLoan->id)
                            ->whereDate('paid_at', '<', $monthStart)
                            ->get(['amount']);

                        foreach ($repayments as $repayment) {
                            $principalPaise -= Money::toPaise(
                                (string) $repayment->amount
                            );
                        }

                        if ($principalPaise < 0) {
                            throw new RuntimeException(
                                'Principal repayment exceeds original loan: '
                                . $lockedLoan->loan_number
                            );
                        }

                        if ($principalPaise === 0) {
                            return 'skipped';
                        }

                        $rateUnits = (int) round(
                            ((float) $lockedLoan->monthly_interest_rate)
                            * 1000
                        );

                        $interestPaise = Money::monthlyInterest(
                            $principalPaise,
                            $rateUnits
                        );

                        if ($interestPaise <= 0) {
                            return 'skipped';
                        }

                        LoanInterestDue::create([
                            'loan_id' => $lockedLoan->id,
                            'interest_month' => $monthStart,
                            'principal_snapshot' =>
                                Money::fromPaise($principalPaise),
                            'interest_rate_snapshot' =>
                                $lockedLoan->monthly_interest_rate,
                            'amount' =>
                                Money::fromPaise($interestPaise),
                            'status' => 'pending',
                        ]);

                        return 'created';
                    }, 3);

                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'existing') {
                        $existing++;
                    } else {
                        $skipped++;
                    }
                }
            });

        return [
            'month' => $month,
            'created' => $created,
            'existing' => $existing,
            'skipped' => $skipped,
        ];
    }


    public function receiveInterest(
        LoanInterestDue $interest,
        int $adminId,
        string $paidAt
    ): LoanInterestDue {
        return DB::transaction(function () use (
            $interest,
            $adminId,
            $paidAt
        ) {

            $this->cashService->lock();

            $due = LoanInterestDue::query()
                ->whereKey($interest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($due->status !== 'pending') {
                throw ValidationException::withMessages([
                    'interest' => 'Interest is already paid.',
                ]);
            }

            $date = CarbonImmutable::parse($paidAt);

            if (
                $date->lessThan(
                    CarbonImmutable::parse($due->interest_month)
                )
                || $date->greaterThan(CarbonImmutable::today())
            ) {
                throw ValidationException::withMessages([
                    'paid_at' => 'Invalid interest payment date.',
                ]);
            }

            if (
                FundEntry::query()
                    ->where('reference_type', 'loan_interest')
                    ->where('reference_id', $due->id)
                    ->exists()
            ) {
                throw new RuntimeException(
                    'Existing interest ledger entry requires reconciliation.'
                );
            }

            $due->update([
                'status' => 'paid',
                'payment_method' => 'cash',
                'paid_at' => $paidAt,
                'received_by' => $adminId,
                'receipt_number' => sprintf(
                    'VF-LI-%08d',
                    $due->id
                ),
            ]);

            FundEntry::create([
                'reference_type' => 'loan_interest',
                'reference_id' => $due->id,
                'direction' => 'credit',
                'amount' => $due->amount,
                'transaction_date' => $paidAt,
                'description' => 'Cash loan interest - loan #'
                    . $due->loan_id,
                'created_by' => $adminId,
            ]);

            return $due->fresh();
        }, 3);
    }

    public function repayPrincipal(
        Loan $loan,
        array $data,
        int $adminId
    ): LoanPrincipalRepayment {
        return DB::transaction(function () use (
            $loan,
            $data,
            $adminId
        ) {

            $this->cashService->lock();

            $lockedLoan = Loan::query()
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = LoanPrincipalRepayment::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing) {
                if (
                    (int) $existing->loan_id !== (int) $lockedLoan->id
                    || Money::toPaise($existing->amount)
                        !== Money::toPaise((string) $data['amount'])
                    || $existing->paid_at->format('Y-m-d')
                        !== $data['paid_at']
                ) {
                    throw ValidationException::withMessages([
                        'idempotency_key' =>
                            'This key belongs to another payment.',
                    ]);
                }

                return $existing;
            }

            if ($lockedLoan->status !== 'active') {
                throw ValidationException::withMessages([
                    'loan' => 'Loan is not active.',
                ]);
            }

            $paidAt = CarbonImmutable::parse($data['paid_at']);

            if (
                $paidAt->lessThan(
                    CarbonImmutable::parse($lockedLoan->disbursed_at)
                )
                || $paidAt->greaterThan(CarbonImmutable::today())
            ) {
                throw ValidationException::withMessages([
                    'paid_at' => 'Invalid principal repayment date.',
                ]);
            }

            /*
             * Protect already-generated monthly interest snapshots.
             * Backdated repayment before the latest generated
             * interest month is not allowed.
             */
            $latestInterestMonth = LoanInterestDue::query()
                ->where('loan_id', $lockedLoan->id)
                ->max('interest_month');

            if (
                $latestInterestMonth
                && $paidAt->format('Y-m-d') < $latestInterestMonth
            ) {
                throw ValidationException::withMessages([
                    'paid_at' =>
                        'Backdated repayment conflicts with generated interest.',
                ]);
            }

            $amountPaise = Money::toPaise(
                (string) $data['amount']
            );

            $outstandingPaise = Money::toPaise(
                $lockedLoan->outstanding_principal
            );

            if (
                $amountPaise <= 0
                || $amountPaise > $outstandingPaise
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount exceeds outstanding principal.',
                ]);
            }

            $repayment = LoanPrincipalRepayment::create([
                'loan_id' => $lockedLoan->id,
                'amount' => Money::fromPaise($amountPaise),
                'paid_at' => $data['paid_at'],
                'payment_method' => 'cash',
                'receipt_number' => 'VF-LP-' . strtoupper(
                    Str::random(16)
                ),
                'idempotency_key' => $data['idempotency_key'],
                'received_by' => $adminId,
            ]);

            $remaining = $outstandingPaise - $amountPaise;

            $lockedLoan->update([
                'outstanding_principal' =>
                    Money::fromPaise($remaining),
                'status' => $remaining === 0
                    ? 'closed'
                    : 'active',
            ]);

            FundEntry::create([
                'reference_type' => 'loan_principal_repayment',
                'reference_id' => $repayment->id,
                'direction' => 'credit',
                'amount' => $repayment->amount,
                'transaction_date' => $data['paid_at'],
                'description' => 'Cash principal repayment - '
                    . $lockedLoan->loan_number,
                'created_by' => $adminId,
            ]);

            return $repayment->fresh();
        }, 3);
    }
}
