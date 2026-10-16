<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FundEntry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialReportController extends Controller
{
    /**
     * Convert signed paise to rupees.
     *
     * Money::fromPaise() does not support
     * negative amounts, so handle the sign here.
     */
    private function signedMoney(int $paise): string
    {
        if ($paise < 0) {
            return '-' . Money::fromPaise(abs($paise));
        }

        return Money::fromPaise($paise);
    }

    /**
     * Validate report filters.
     */
    private function filters(Request $request): array
    {
        $data = Validator::make(
            $request->all(),
            [
                'from' => [
                    'nullable',
                    'date_format:Y-m-d',
                ],
                'to' => [
                    'nullable',
                    'date_format:Y-m-d',
                ],
                'page' => [
                    'nullable',
                    'integer',
                    'min:1',
                ],
                'per_page' => [
                    'nullable',
                    'integer',
                    'min:1',
                    'max:100',
                ],
            ]
        )->validate();

        if (
            !empty($data['from']) &&
            !empty($data['to']) &&
            $data['from'] > $data['to']
        ) {
            throw ValidationException::withMessages([
                'to' =>
                    'To date must be on or after From date.',
            ]);
        }

        return $data;
    }

    /**
     * Apply date filters to the Fund Ledger.
     */
    private function filteredQuery(array $filters)
    {
        return FundEntry::query()
            ->when(
                !empty($filters['from']),
                function ($query) use ($filters) {
                    $query->whereDate(
                        'transaction_date',
                        '>=',
                        $filters['from']
                    );
                }
            )
            ->when(
                !empty($filters['to']),
                function ($query) use ($filters) {
                    $query->whereDate(
                        'transaction_date',
                        '<=',
                        $filters['to']
                    );
                }
            );
    }

    /**
     * Calculate report totals and category breakdown.
     *
     * All calculations are performed in paise
     * to avoid floating-point precision issues.
     */
    private function calculateTotals(array $filters): array
    {
        $credits = 0;
        $debits = 0;
        $transactionCount = 0;

        $categories = [];

        $this->filteredQuery($filters)
            ->select([
                'id',
                'direction',
                'amount',
                'reference_type',
            ])
            ->orderBy('id')
            ->chunkById(
                500,
                function ($entries) use (
                    &$credits,
                    &$debits,
                    &$transactionCount,
                    &$categories
                ) {
                    foreach ($entries as $entry) {
                        $amount = Money::toPaise(
                            (string) $entry->amount
                        );

                        $direction = $entry->direction;

                        $category =
                            $entry->reference_type ?: 'other';

                        if (!isset($categories[$category])) {
                            $categories[$category] = [
                                'reference_type' => $category,
                                'credit_paise' => 0,
                                'debit_paise' => 0,
                                'count' => 0,
                            ];
                        }

                        if ($direction === 'credit') {
                            $credits += $amount;

                            $categories[$category][
                                'credit_paise'
                            ] += $amount;
                        } elseif ($direction === 'debit') {
                            $debits += $amount;

                            $categories[$category][
                                'debit_paise'
                            ] += $amount;
                        }

                        $transactionCount++;

                        $categories[$category]['count']++;
                    }
                }
            );

        $categoryRows = [];

        foreach ($categories as $category) {
            $categoryRows[] = [
                'reference_type' =>
                    $category['reference_type'],

                'total_credit' => $this->signedMoney(
                    $category['credit_paise']
                ),

                'total_debit' => $this->signedMoney(
                    $category['debit_paise']
                ),

                'net_amount' => $this->signedMoney(
                    $category['credit_paise'] -
                    $category['debit_paise']
                ),

                'transaction_count' =>
                    $category['count'],
            ];
        }

        usort(
            $categoryRows,
            fn ($a, $b) => strcmp(
                $a['reference_type'],
                $b['reference_type']
            )
        );

        return [
            'total_credit_paise' => $credits,
            'total_debit_paise' => $debits,
            'transaction_count' => $transactionCount,
            'categories' => $categoryRows,
        ];
    }

    /**
     * Calculate the cash balance before
     * the selected From Date.
     */
    private function openingBalance(?string $from): int
    {
        if (!$from) {
            return 0;
        }

        $balance = 0;

        FundEntry::query()
            ->whereDate(
                'transaction_date',
                '<',
                $from
            )
            ->select([
                'id',
                'direction',
                'amount',
            ])
            ->orderBy('id')
            ->chunkById(
                500,
                function ($entries) use (&$balance) {
                    foreach ($entries as $entry) {
                        $amount = Money::toPaise(
                            (string) $entry->amount
                        );

                        if ($entry->direction === 'credit') {
                            $balance += $amount;
                        } elseif ($entry->direction === 'debit') {
                            $balance -= $amount;
                        }
                    }
                }
            );

        return $balance;
    }

    /**
     * Financial Report API.
     *
     * GET /api/v1/admin/reports/financial
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        $openingBalance = $this->openingBalance(
            $filters['from'] ?? null
        );

        $totals = $this->calculateTotals($filters);

        $netChange =
            $totals['total_credit_paise'] -
            $totals['total_debit_paise'];

        $closingBalance =
            $openingBalance + $netChange;

        $entries = $this->filteredQuery($filters)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(
                (int) ($filters['per_page'] ?? 30)
            );

        return response()->json([
            'success' => true,

            'data' => [
                'period' => [
                    'from' =>
                        $filters['from'] ?? null,

                    'to' =>
                        $filters['to'] ?? null,
                ],

                'summary' => [
                    'opening_balance' =>
                        $this->signedMoney(
                            $openingBalance
                        ),

                    'total_credit' =>
                        $this->signedMoney(
                            $totals['total_credit_paise']
                        ),

                    'total_debit' =>
                        $this->signedMoney(
                            $totals['total_debit_paise']
                        ),

                    'net_change' =>
                        $this->signedMoney(
                            $netChange
                        ),

                    'closing_balance' =>
                        $this->signedMoney(
                            $closingBalance
                        ),

                    'transaction_count' =>
                        $totals['transaction_count'],
                ],

                'categories' =>
                    $totals['categories'],

                'entries' => $entries,
            ],
        ]);
    }

    /**
     * Export all matching ledger entries to CSV.
     *
     * GET /api/v1/admin/reports/financial/export
     */
    public function export(
        Request $request
    ): StreamedResponse {
        $filters = $this->filters($request);

        $filename =
            'venidas-financial-report-' .
            now()->format('Ymd-His') .
            '.csv';

        return response()->streamDownload(
            function () use ($filters) {
                $handle = fopen(
                    'php://output',
                    'w'
                );

                // UTF-8 BOM for Excel.
                fwrite(
                    $handle,
                    "\xEF\xBB\xBF"
                );

                fputcsv($handle, [
                    'Entry ID',
                    'Transaction Date',
                    'Direction',
                    'Reference Type',
                    'Reference ID',
                    'Credit',
                    'Debit',
                ]);

                foreach (
                    $this->filteredQuery($filters)
                        ->orderBy('transaction_date')
                        ->orderBy('id')
                        ->cursor() as $entry
                ) {
                    $amount = Money::fromPaise(
                        Money::toPaise(
                            (string) $entry->amount
                        )
                    );

                    fputcsv($handle, [
                        $entry->id,

                        $entry->transaction_date
                            ? CarbonImmutable::parse(
                                $entry->transaction_date
                            )->format('Y-m-d')
                            : '',

                        $entry->direction,

                        $entry->reference_type,

                        $entry->reference_id,

                        $entry->direction === 'credit'
                            ? $amount
                            : '0.00',

                        $entry->direction === 'debit'
                            ? $amount
                            : '0.00',
                    ]);
                }

                fclose($handle);
            },
            $filename,
            [
                'Content-Type' =>
                    'text/csv; charset=UTF-8',

                'Cache-Control' =>
                    'no-store, private',
            ]
        );
    }
}
