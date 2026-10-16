<?php

namespace App\Console\Commands;

use App\Models\FundEntry;
use App\Models\Loan;
use App\Models\LoanInterestDue;
use App\Models\User;
use App\Services\LoanService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportHistoricalLoan extends Command
{
    protected $signature = 'loans:import-history
        {--dry-run : Preview only, without saving}';

    protected $description =
        'Create a historical loan with monthly paid interest entries';

    public function handle(LoanService $loanService): int
    {
        $this->info('VENIDAS FINANCE - Historical Loan Import');

        $admins = User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($admins->isEmpty()) {
            $this->error('No admin user found.');
            return self::FAILURE;
        }

        $this->table(
            ['Admin ID', 'Name'],
            $admins->map(fn ($u) => [$u->id, $u->name])->all()
        );

        $adminId = (int) $this->ask('Enter Admin ID');

        if (!$admins->contains('id', $adminId)) {
            $this->error('Invalid Admin ID.');
            return self::FAILURE;
        }

        $borrowers = User::query()
            ->where('is_active', true)
            ->whereIn('role', ['member', 'outsider'])
            ->orderBy('id')
            ->get(['id', 'name', 'role']);

        if ($borrowers->isEmpty()) {
            $this->error('No active borrowers found.');
            return self::FAILURE;
        }

        $this->table(
            ['User ID', 'Name', 'Type'],
            $borrowers->map(
                fn ($u) => [$u->id, $u->name, $u->role]
            )->all()
        );

        $borrowerId = (int) $this->ask('Enter Borrower User ID');

        $borrower = $borrowers->firstWhere('id', $borrowerId);

        if (!$borrower) {
            $this->error('Invalid borrower.');
            return self::FAILURE;
        }

        $dateInput = $this->ask(
            'Loan taken date (YYYY-MM-DD)',
            '2025-01-15'
        );

        $loanDate = $this->parseDate($dateInput);

        if (!$loanDate || $loanDate->greaterThan(CarbonImmutable::today())) {
            $this->error('Invalid loan date.');
            return self::FAILURE;
        }

        $amountInput = $this->ask('Principal amount in rupees');

        try {
            $principalPaise = Money::toPaise((string) $amountInput);
        } catch (Throwable $e) {
            $this->error('Invalid principal amount.');
            return self::FAILURE;
        }

        if ($principalPaise <= 0) {
            $this->error('Principal must be positive.');
            return self::FAILURE;
        }

        $isClosed = $this->confirm('Is this loan CLOSED?', false);

        $closedDate = null;

        if ($isClosed) {
            $closedInput = $this->ask(
                'Loan closed date (YYYY-MM-DD)'
            );

            $closedDate = $this->parseDate($closedInput);

            if (
                !$closedDate ||
                $closedDate->lessThan($loanDate) ||
                $closedDate->greaterThan(CarbonImmutable::today())
            ) {
                $this->error('Invalid loan close date.');
                return self::FAILURE;
            }
        }

        $firstMonth = $loanDate
            ->startOfMonth()
            ->addMonth();

        $defaultEnd = $closedDate
            ? $closedDate->format('Y-m')
            : CarbonImmutable::today()->format('Y-m');

        $paidThroughInput = $this->ask(
            'Interest actually PAID through month (YYYY-MM)',
            $defaultEnd
        );

        $paidThrough = $this->parseMonth($paidThroughInput);

        if (
            !$paidThrough ||
            $paidThrough->greaterThan(
                CarbonImmutable::today()->startOfMonth()
            ) ||
            (
                $closedDate &&
                $paidThrough->greaterThan(
                    $closedDate->startOfMonth()
                )
            )
        ) {
            $this->error('Invalid paid-through month.');
            return self::FAILURE;
        }

        $paymentDay = (int) $this->ask(
            'Payment day each month (1-31, 0 = month end)',
            '0'
        );

        if ($paymentDay < 0 || $paymentDay > 31) {
            $this->error('Invalid payment day.');
            return self::FAILURE;
        }

        // A closed loan may need interest for its closing month.
        // The payment date must never exceed the closing date.
        $items = [];

        for (
            $month = $firstMonth;
            $month->lessThanOrEqualTo($paidThrough);
            $month = $month->addMonth()
        ) {
            $monthEnd = $month->endOfMonth();

            $day = $paymentDay === 0
                ? $monthEnd->day
                : min($paymentDay, $monthEnd->day);

            $paidAt = $month->setDay($day);

            if (
                $closedDate &&
                $month->format('Y-m') === $closedDate->format('Y-m')
            ) {
                $paidAt = $paidAt->greaterThan($closedDate)
                    ? $closedDate
                    : $paidAt;
            }

            if ($paidAt->greaterThan(CarbonImmutable::today())) {
                $paidAt = CarbonImmutable::today();
            }

            $items[] = [
                'month' => $month->format('Y-m-d'),
                'paid_at' => $paidAt->format('Y-m-d'),
            ];
        }

        // Avoid creating the same historical loan twice.
        $duplicate = Loan::query()
            ->where('borrower_id', $borrowerId)
            ->whereDate('disbursed_at', $loanDate->format('Y-m-d'))
            ->where('principal_amount', Money::fromPaise($principalPaise))
            ->exists();

        if ($duplicate) {
            $this->error(
                'A loan with this borrower, date and amount already exists.'
            );
            return self::FAILURE;
        }

        $rate = $borrower->role === 'member'
            ? '1.000'
            : '2.000';

        $interestPaise = Money::monthlyInterest(
            $principalPaise,
            (int) round(((float) $rate) * 1000)
        );

        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Borrower', $borrower->name],
                ['Loan Type', $borrower->role],
                ['Loan Date', $loanDate->format('Y-m-d')],
                ['Principal', Money::fromPaise($principalPaise)],
                ['Monthly Rate', $rate . '%'],
                ['Monthly Interest', Money::fromPaise($interestPaise)],
                ['Loan Status', $isClosed ? 'CLOSED' : 'ACTIVE'],
                ['Closed Date', $closedDate?->format('Y-m-d') ?? '-'],
                ['Paid Interest Months', count($items)],
                [
                    'Total Paid Interest',
                    Money::fromPaise($interestPaise * count($items))
                ],
            ]
        );

        $this->table(
            ['Interest Month', 'Paid Date', 'Amount'],
            array_map(
                fn ($item) => [
                    $item['month'],
                    $item['paid_at'],
                    Money::fromPaise($interestPaise),
                ],
                $items
            )
        );

        if ($isClosed && $paidThrough->lessThan(
            $closedDate->startOfMonth()
        )) {
            $this->warn(
                'Loan closing month has no paid interest entry. '
                . 'Confirm this matches the real records.'
            );
        }

        if ($this->option('dry-run')) {
            $this->info('DRY RUN: No database changes made.');
            return self::SUCCESS;
        }

        if (!$this->confirm(
            'Create loan and all listed PAID interest entries?',
            false
        )) {
            $this->warn('Cancelled.');
            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use (
                $loanService,
                $borrower,
                $borrowerId,
                $adminId,
                $loanDate,
                $principalPaise,
                $items,
                $isClosed,
                $closedDate
            ) {
                // Existing service also creates the loan disbursement
                // cash ledger entry and checks available society cash.
                $loan = $loanService->create([
                    'borrower_id' => $borrowerId,
                    'loan_type' => $borrower->role,
                    'principal_amount' =>
                        Money::fromPaise($principalPaise),
                    'disbursed_at' => $loanDate->format('Y-m-d'),
                    'disbursement_method' => 'cash',
                    'idempotency_key' => (string) Str::uuid(),
                    'purpose' => null,
                    'notes' => 'Historical loan import',
                ], $adminId);

                foreach ($items as $item) {
                    $monthStart = $item['month'];

                    $exists = LoanInterestDue::query()
                        ->where('loan_id', $loan->id)
                        ->where('interest_month', $monthStart)
                        ->exists();

                    if ($exists) {
                        throw new \RuntimeException(
                            'Duplicate interest month: ' . $monthStart
                        );
                    }

                    $rateUnits = (int) round(
                        ((float) $loan->monthly_interest_rate) * 1000
                    );

                    $interestAmount = Money::monthlyInterest(
                        $principalPaise,
                        $rateUnits
                    );

                    $due = LoanInterestDue::create([
                        'loan_id' => $loan->id,
                        'interest_month' => $monthStart,
                        'principal_snapshot' =>
                            Money::fromPaise($principalPaise),
                        'interest_rate_snapshot' =>
                            $loan->monthly_interest_rate,
                        'amount' =>
                            Money::fromPaise($interestAmount),
                        'status' => 'pending',
                    ]);

                    // Existing service marks the due paid and creates
                    // the matching fund_entries cash credit.
                    $loanService->receiveInterest(
                        $due,
                        $adminId,
                        $item['paid_at']
                    );
                }

                if ($isClosed) {
                    // Existing service records full principal repayment,
                    // updates loan status, and credits the cash ledger.
                    $loanService->repayPrincipal(
                        $loan,
                        [
                            'amount' =>
                                Money::fromPaise($principalPaise),
                            'paid_at' =>
                                $closedDate->format('Y-m-d'),
                            'idempotency_key' => (string) Str::uuid(),
                        ],
                        $adminId
                    );
                }

                $this->info(
                    'Created Loan: ' . $loan->loan_number
                );
            }, 3);
        } catch (Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());
            $this->warn('Transaction rolled back.');
            return self::FAILURE;
        }

        $this->info('Historical loan import completed successfully.');

        return self::SUCCESS;
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if (
            !$value ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
        ) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $value
            );

            return $date && $date->format('Y-m-d') === $value
                ? $date
                : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function parseMonth(?string $value): ?CarbonImmutable
    {
        if (
            !$value ||
            !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)
        ) {
            return null;
        }

        return $this->parseDate($value . '-01');
    }
}
