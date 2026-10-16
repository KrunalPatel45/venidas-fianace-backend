<?php

namespace App\Services;

use App\Models\FundEntry;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SocietyCashService
{
    /**
     * Must be called inside a DB transaction.
     *
     * Every cash writer must acquire this lock first.
     */
    public function lock(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'Cash lock requires an active database transaction.'
            );
        }

        $lock = DB::table('society_cash_locks')
            ->where('id', 1)
            ->lockForUpdate()
            ->first();

        if (!$lock) {
            throw new RuntimeException(
                'Society cash lock row is missing.'
            );
        }
    }

    /**
     * Exact cash balance in integer paise.
     *
     * Caller must hold the cash lock when using
     * this for a financial write.
     */
    public function balancePaise(): int
    {
        $credits = 0;
        $debits = 0;

        FundEntry::query()
            ->select(['id', 'direction', 'amount'])
            ->orderBy('id')
            ->chunkById(500, function ($entries) use (
                &$credits,
                &$debits
            ) {
                foreach ($entries as $entry) {
                    $paise = Money::toPaise(
                        (string) $entry->amount
                    );

                    if ($entry->direction === 'credit') {
                        $credits += $paise;
                    } elseif ($entry->direction === 'debit') {
                        $debits += $paise;
                    } else {
                        throw new RuntimeException(
                            'Invalid fund direction.'
                        );
                    }
                }
            });

        return $credits - $debits;
    }

    public function balance(): array
    {
        $balance = $this->balancePaise();

        return [
            'balance' => $this->signedAmount($balance),
            'currency' => 'INR',
        ];
    }

    private function signedAmount(int $paise): string
    {
        return ($paise < 0 ? '-' : '')
            . Money::fromPaise(abs($paise));
    }
}
