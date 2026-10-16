<?php

namespace Database\Seeders;

use App\Models\FundEntry;
use App\Models\MonthlyInvestment;
use App\Models\SocietyAccount;
use App\Models\User;
use App\Services\MonthlyInvestmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TestHistoricalMonthlyInvestmentSeeder extends Seeder
{
    private const START_MONTH = '2024-10';
    private const END_MONTH = '2026-09';

    public function run(): void
    {
        /*
         * STRICT SAFETY:
         * Never run simulated payments in production.
         */
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'This historical payment seeder is allowed only in local/testing.'
            );
        }

        if (!filter_var(
            env('ALLOW_FAKE_HISTORICAL_PAYMENTS', false),
            FILTER_VALIDATE_BOOLEAN
        )) {
            throw new RuntimeException(
                'Set ALLOW_FAKE_HISTORICAL_PAYMENTS=true in your TEST .env.'
            );
        }

        /*
         * Use an existing active admin as the person
         * who received the simulated cash.
         */
        $admin = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$admin) {
            throw new RuntimeException(
                'No active admin found. Create an admin first.'
            );
        }

        $service = app(MonthlyInvestmentService::class);

        $start = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            self::START_MONTH . '-01'
        );

        $end = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            self::END_MONTH . '-01'
        );

        $totalCreated = 0;
        $totalPaid = 0;
        $totalAlreadyPaid = 0;
        $totalSkipped = 0;

        /*
         * Preflight checks before modifying any records.
         */
        $missingAccounts = SocietyAccount::query()
            ->where('status', 'active')
            ->whereNull('opened_at')
            ->count();

        if ($missingAccounts > 0) {
            throw new RuntimeException(
                "{$missingAccounts} active accounts have no opening date."
            );
        }

        $this->command?->info(
            'Starting historical monthly investment seeder...'
        );

        /*
         * Run each month sequentially.
         */
        for (
            $month = $start;
            $month->lessThanOrEqualTo($end);
            $month = $month->addMonth()
        ) {
            $monthString = $month->format('Y-m');
            $monthStart = $month->format('Y-m-d');

            /*
             * Simulate cash collection on the last day
             * of each investment month.
             *
             * These are DEMO dates, not actual receipts.
             */
            $paidAt = $month
                ->endOfMonth()
                ->format('Y-m-d');

            $this->command?->info(
                "Processing {$monthString}..."
            );

            /*
             * Existing service creates only missing dues
             * for active, eligible accounts.
             */
            $generation = $service->generate($monthString);

            $totalCreated += (int) ($generation['created'] ?? 0);

            /*
             * Process only eligible existing dues.
             * Existing paid entries are never paid twice.
             */
            MonthlyInvestment::query()
                ->whereDate('investment_month', $monthStart)
                ->whereHas('account', function ($query) use ($month) {
                    $query
                        ->where('status', 'active')
                        ->whereDate(
                            'opened_at',
                            '<=',
                            $month->endOfMonth()->format('Y-m-d')
                        );
                })
                ->orderBy('id')
                ->chunkById(100, function ($investments) use (
                    $service,
                    $admin,
                    $paidAt,
                    &$totalPaid,
                    &$totalAlreadyPaid,
                    &$totalSkipped
                ) {
                    foreach ($investments as $investment) {
                        if ($investment->status === 'paid') {
                            /*
                             * Verify an existing paid due has
                             * a matching cash credit ledger.
                             */
                            $ledger = FundEntry::query()
                                ->where(
                                    'reference_type',
                                    'monthly_investment'
                                )
                                ->where(
                                    'reference_id',
                                    $investment->id
                                )
                                ->where('direction', 'credit')
                                ->first();

                            if (
                                !$ledger ||
                                $investment->payment_method !== 'cash' ||
                                abs(
                                    (float) $ledger->amount -
                                    (float) $investment->amount
                                ) > 0.001
                            ) {
                                throw new RuntimeException(
                                    'Paid investment has missing or inconsistent '
                                    . 'cash ledger. Investment ID: '
                                    . $investment->id
                                );
                            }

                            $totalAlreadyPaid++;
                            continue;
                        }

                        if ($investment->status !== 'pending') {
                            $totalSkipped++;
                            continue;
                        }

                        /*
                         * Existing service:
                         * - locks society cash
                         * - locks monthly investment
                         * - validates payment date
                         * - updates status to paid
                         * - creates receipt number
                         * - creates FundEntry credit
                         * - commits atomically
                         */
                        try {
                            $service->receiveCash(
                                $investment,
                                $admin->id,
                                $paidAt
                            );

                            $totalPaid++;
                        } catch (Throwable $exception) {
                            throw new RuntimeException(
                                'Historical payment failed for investment ID '
                                . $investment->id
                                . ': '
                                . $exception->getMessage(),
                                0,
                                $exception
                            );
                        }
                    }
                });

            $this->command?->info(
                "Completed {$monthString}"
            );
        }

        /*
         * Final verification for the historical period.
         */
        $summary = DB::table('monthly_investments')
            ->whereBetween('investment_month', [
                self::START_MONTH . '-01',
                self::END_MONTH . '-01',
            ])
            ->selectRaw('COUNT(*) AS total_entries')
            ->selectRaw(
                "SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_entries"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_entries"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS paid_amount"
            )
            ->first();

        $this->command?->newLine();
        $this->command?->info('Historical seeding completed.');
        $this->command?->table(
            ['Metric', 'Value'],
            [
                ['Period', self::START_MONTH . ' to ' . self::END_MONTH],
                ['New dues created', $totalCreated],
                ['Payments newly received', $totalPaid],
                ['Previously paid', $totalAlreadyPaid],
                ['Other statuses skipped', $totalSkipped],
                ['Total historical entries', $summary->total_entries],
                ['Total paid entries', $summary->paid_entries],
                ['Remaining pending entries', $summary->pending_entries],
                ['Total paid amount (INR)', $summary->paid_amount],
            ]
        );
    }
}