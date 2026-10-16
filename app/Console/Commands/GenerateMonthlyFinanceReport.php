<?php

namespace App\Console\Commands;

use App\Services\FinanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateMonthlyFinanceReport extends Command
{
    protected $signature = 'finance:monthly-report
        {--month= : Report month in YYYY-MM format}';

    protected $description = 'Generate monthly society finance report';

    public function handle(
        FinanceReportService $reports
    ): int {
        $month = $this->option('month');

        if (!$month) {
            $month = CarbonImmutable::now('Asia/Kolkata')
                ->subMonthNoOverflow()
                ->format('Y-m');
        }

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('Month must be YYYY-MM.');

            return self::FAILURE;
        }

        $data = $reports->monthly($month);

        $path = "finance-reports/{$month}.json";

        Storage::disk('local')->put(
            $path,
            json_encode(
                [
                    'generated_at' => now('Asia/Kolkata')
                        ->toIso8601String(),
                    'report' => $data,
                ],
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            )
        );

        $this->info("Report generated: {$path}");

        return self::SUCCESS;
    }
}
