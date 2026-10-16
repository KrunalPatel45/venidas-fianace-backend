<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FundEntry;
use App\Models\Loan;
use App\Models\LoanInterestDue;
use App\Models\LoanPrincipalRepayment;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

class FinancialReconciliationController extends Controller
{
    public function index(): JsonResponse
    {
        $issues = [];

        Loan::query()
            ->orderBy('id')
            ->chunkById(100, function ($loans) use (&$issues) {
                foreach ($loans as $loan) {
                    $principal = Money::toPaise(
                        (string) $loan->principal_amount
                    );

                    $repaid = 0;

                    LoanPrincipalRepayment::query()
                        ->where('loan_id', $loan->id)
                        ->orderBy('id')
                        ->chunkById(200, function ($payments) use (
                            &$repaid,
                            &$issues
                        ) {
                            foreach ($payments as $payment) {
                                $repaid += Money::toPaise(
                                    (string) $payment->amount
                                );

                                $this->checkLedger(
                                    'loan_principal_repayment',
                                    $payment->id,
                                    'credit',
                                    (string) $payment->amount,
                                    $issues
                                );
                            }
                        });

                    $expected = $principal - $repaid;

                    if ($expected < 0) {
                        $issues[] = [
                            'type' => 'over_repayment',
                            'loan_id' => $loan->id,
                        ];
                    }

                    if (
                        $expected >= 0
                        && $expected !== Money::toPaise(
                            (string) $loan->outstanding_principal
                        )
                    ) {
                        $issues[] = [
                            'type' => 'principal_mismatch',
                            'loan_id' => $loan->id,
                            'expected_outstanding' =>
                                Money::fromPaise($expected),
                            'stored_outstanding' =>
                                $loan->outstanding_principal,
                        ];
                    }

                    if (
                        ($expected === 0 && $loan->status !== 'closed')
                        || ($expected > 0 && $loan->status !== 'active')
                    ) {
                        $issues[] = [
                            'type' => 'loan_status_mismatch',
                            'loan_id' => $loan->id,
                        ];
                    }

                    $this->checkLedger(
                        'loan_disbursement',
                        $loan->id,
                        'debit',
                        (string) $loan->principal_amount,
                        $issues
                    );
                }
            });

        LoanInterestDue::query()
            ->where('status', 'paid')
            ->orderBy('id')
            ->chunkById(200, function ($dues) use (&$issues) {
                foreach ($dues as $due) {
                    $this->checkLedger(
                        'loan_interest',
                        $due->id,
                        'credit',
                        (string) $due->amount,
                        $issues
                    );
                }
            });

        return response()->json([
            'success' => true,
            'data' => [
                'is_reconciled' => count($issues) === 0,
                'issue_count' => count($issues),
                'issues' => $issues,
            ],
        ]);
    }

    private function checkLedger(
        string $referenceType,
        int $referenceId,
        string $direction,
        string $amount,
        array &$issues
    ): void {
        $entries = FundEntry::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->get();

        if ($entries->count() !== 1) {
            $issues[] = [
                'type' => 'ledger_count_mismatch',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'expected_count' => 1,
                'actual_count' => $entries->count(),
            ];

            return;
        }

        $entry = $entries->first();

        if (
            $entry->direction !== $direction
            || Money::toPaise((string) $entry->amount)
                !== Money::toPaise($amount)
        ) {
            $issues[] = [
                'type' => 'ledger_amount_or_direction_mismatch',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ];
        }
    }
}
