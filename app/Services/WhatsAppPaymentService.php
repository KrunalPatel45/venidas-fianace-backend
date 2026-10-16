<?php

namespace App\Services;

use App\Models\LoanInterestDue;
use App\Models\MonthlyInvestment;
use App\Models\WhatsAppNotification;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WhatsAppPaymentService
{
    private function money(string $amount): string
    {
        $paise = Money::toPaise($amount);

        $rupees = intdiv($paise, 100);
        $remainder = $paise % 100;

        $formatted = number_format($rupees, 0, '.', ',');

        if ($remainder !== 0) {
            $formatted .= '.' . str_pad(
                (string) $remainder,
                2,
                '0',
                STR_PAD_LEFT
            );
        }

        return '₹' . $formatted;
    }

    public function investment(MonthlyInvestment $investment): void
    {
        if ($investment->status !== 'paid') {
            throw new RuntimeException('Investment is not paid.');
        }

        $row = DB::table('society_accounts as sa')
            ->join('users as u', 'u.id', '=', 'sa.user_id')
            ->where('sa.id', $investment->society_account_id)
            ->select('u.name', 'u.name_gu')
            ->first();

        if (!$row) {
            throw new RuntimeException('Investment member not found.');
        }

        $name = trim($row->name_gu ?: $row->name);

        WhatsAppNotification::firstOrCreate(
            [
                'event_key' => 'investment:' . $investment->id,
            ],
            [
                'message' => $name . ' - '
                    . $this->money((string) $investment->amount),
                'status' => 'pending',
            ]
        );
    }

    public function interest(LoanInterestDue $interest): void
    {
        if ($interest->status !== 'paid') {
            throw new RuntimeException('Interest is not paid.');
        }

        $row = DB::table('loans as l')
            ->join('users as u', 'u.id', '=', 'l.borrower_id')
            ->where('l.id', $interest->loan_id)
            ->select('u.name', 'u.name_gu')
            ->first();

        if (!$row) {
            throw new RuntimeException('Loan borrower not found.');
        }

        $name = trim($row->name_gu ?: $row->name);

        WhatsAppNotification::firstOrCreate(
            [
                'event_key' => 'interest:' . $interest->id,
            ],
            [
                'message' => $name . ' - '
                    . $this->money((string) $interest->amount)
                    . ' વ્યાજ',
                'status' => 'pending',
            ]
        );
    }

    public function bulkInvestment(array $investmentIds): void
    {
        $investmentIds = array_values(array_unique(
            array_map('intval', $investmentIds)
        ));

        if (!$investmentIds) {
            throw new RuntimeException('No investments supplied.');
        }

        $rows = DB::table('monthly_investments as mi')
            ->join(
                'society_accounts as sa',
                'sa.id',
                '=',
                'mi.society_account_id'
            )
            ->join('users as u', 'u.id', '=', 'sa.user_id')
            ->whereIn('mi.id', $investmentIds)
            ->select(
                'mi.id',
                'mi.amount',
                'mi.status',
                'sa.user_id',
                'u.name',
                'u.name_gu'
            )
            ->get();

        if ($rows->count() !== count($investmentIds)) {
            throw new RuntimeException('Some investments were not found.');
        }

        if ($rows->contains(fn ($row) => $row->status !== 'paid')) {
            throw new RuntimeException('Some investments are not paid.');
        }

        if ($rows->pluck('user_id')->unique()->count() !== 1) {
            throw new RuntimeException(
                'Bulk WhatsApp message requires one member.'
            );
        }

        $totalPaise = 0;

        foreach ($rows as $row) {
            $totalPaise += Money::toPaise((string) $row->amount);
        }

        $first = $rows->first();
        $name = trim($first->name_gu ?: $first->name);

        sort($investmentIds, SORT_NUMERIC);

        $eventKey = 'bulk:' . hash(
            'sha256',
            implode(',', $investmentIds)
        );

        WhatsAppNotification::firstOrCreate(
            ['event_key' => $eventKey],
            [
                'message' => $name . ' - '
                    . $this->money(Money::fromPaise($totalPaise)),
                'status' => 'pending',
            ]
        );
    }
}
