<?php

namespace App\Services;

use App\Models\AccountOpeningFee;
use App\Models\FundEntry;
use App\Models\SocietyAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\SocietyCashService;

class AccountService
{
    public function __construct(
        private readonly SocietyCashService $cashService
    ) {
    }

    public function createAccount(array $data): SocietyAccount
    {
        return DB::transaction(function () use ($data) {

            $member = User::whereKey($data['user_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($member->role !== 'member' || !$member->is_active) {
                throw ValidationException::withMessages([
                    'user_id' => 'An active society member is required.',
                ]);
            }

            $account = SocietyAccount::create([
                'user_id' => $member->id,
                'account_number' => 'VF-'.strtoupper(
                    bin2hex(random_bytes(8))
                ),
                'monthly_amount' => 1000,
                'opening_fee_amount' => 100,
                'opened_at' => $data['opened_at'],
                'status' => 'active',
            ]);

            $account->openingFee()->create([
                'amount' => 100,
                'status' => 'pending',
            ]);

            return $account->load('openingFee');
        });
    }

    public function receiveOpeningFee(
        AccountOpeningFee $openingFee,
        array $data,
        User $admin
    ): AccountOpeningFee {

        return DB::transaction(function () use (
            $openingFee,
            $data,
            $admin
        ) {
            
            $this->cashService->lock();

            $fee = AccountOpeningFee::whereKey($openingFee->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fee->status === 'paid') {
                throw ValidationException::withMessages([
                    'opening_fee' => 'Opening fee has already been paid.',
                ]);
            }

            if ($data['paid_at'] < $fee->account->opened_at->format('Y-m-d')) {
                throw ValidationException::withMessages([
                    'paid_at' => 'Payment date cannot be before account opening date.',
                ]);
            }

            $receipt = 'VF-OF-'.str_pad(
                (string) $fee->id,
                8,
                '0',
                STR_PAD_LEFT
            );

            $fee->update([
                'status' => 'paid',
                'paid_at' => $data['paid_at'],
                'receipt_number' => $receipt,
                'payment_method' => $data['payment_method'],
                'payment_reference' => $data['payment_reference'] ?? null,
                'received_by' => $admin->id,
            ]);

            FundEntry::create([
                'reference_type' => 'opening_fee',
                'reference_id' => $fee->id,
                'direction' => 'credit',
                'amount' => $fee->amount,
                'transaction_date' => $data['paid_at'],
                'description' => 'One-time account opening fee',
                'created_by' => $admin->id,
            ]);

            return $fee->fresh();
        });
    }
}