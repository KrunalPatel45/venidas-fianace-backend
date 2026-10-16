<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\SocietyAccount;
use App\Models\AccountOpeningFee;
use App\Models\FundEntry;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class HistoricalSocietyMembersSeeder extends Seeder
{
    private const MONTHLY_AMOUNT = '1000.00';
    private const OPENING_FEE = '100.00';

    private const EXPECTED_MEMBERS = 31;
    private const EXPECTED_ACCOUNTS = 110;

    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Historical Dates
        |--------------------------------------------------------------------------
        |
        | Both dates must be configured in .env.
        | Do not guess historical financial dates.
        |
        */

        $openingDate = env('SOCIETY_HISTORICAL_OPENING_DATE');
        $postingDate = env('SOCIETY_HISTORICAL_FEE_POSTING_DATE');

        $this->validateDate($openingDate, 'SOCIETY_HISTORICAL_OPENING_DATE');
        $this->validateDate($postingDate, 'SOCIETY_HISTORICAL_FEE_POSTING_DATE');

        if ($postingDate < $openingDate) {
            throw new RuntimeException(
                'Fee posting date cannot be earlier than account opening date.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Historical Members
        |--------------------------------------------------------------------------
        |
        | [Member ID, English Name, Gujarati Name, Account Count]
        |
        | Verify name spellings with the original society register.
        |
        */

        $members = [
            ['PGP011', 'Piyush Gopalbhai Patel', 'પીયુષ ગોપાલભાઈ પટેલ', 11],
            ['ARP008', 'Ashwinbhai Ratilal Patel', 'અશ્વિનભાઈ રતિલાલ પટેલ', 8],
            ['RKP006', 'Rajendrabhai Kantibhai Patel', 'રાજેન્દ્રભાઈ કાંતિભાઈ પટેલ', 6],
            ['DCP005', 'Dilipbhai Chandubhai Patel', 'દિલીપભાઈ ચંદુભાઈ પટેલ', 5],
            ['VVP005', 'Vinay Vishnubhai Patel', 'વિનય વિષ્ણુભાઈ પટેલ', 5],
            ['MSP005', 'Meet Sanjaybhai Patel', 'મીત સંજયભાઈ પટેલ', 5],
            ['UE005', 'Uma Enterprise', 'ઉમા એન્ટરપ્રાઇઝ', 5],
            ['JRP005', 'Jitendra Rameshbhai Patel', 'જિતેન્દ્ર રમેશભાઈ પટેલ', 5],
            ['MDP005', 'Mukesh Dahyabhai Patel', 'મુકેશ ડાહ્યાભાઈ પટેલ', 5],
            ['CDP005', 'Chintan Dashrathbhai Patel', 'ચિંતન દશરથભાઈ પટેલ', 5],
            ['DMP005', 'Dharmendra Manibhai Patel', 'ધર્મેન્દ્ર મણીભાઈ પટેલ', 5],
            ['JKP003', 'Jagdishbhai Kanubhai Patel', 'જગદીશભાઈ કનુભાઈ પટેલ', 3],
            ['VRP003', 'Viraj Rameshbhai Patel', 'વિરાજ રમેશભાઈ પટેલ', 3],
            ['CKP004', 'Chetan Keshavbhai Patel', 'ચેતન કેશવભાઈ પટેલ', 4],
            ['KKP004', 'Kaushik Kantibhai Patel', 'કૌશિક કાંતિભાઈ પટેલ', 4],
            ['JDP003', 'Jignesh Dineshbhai Patel', 'જિગ્નેશ દિનેશભાઈ પટેલ', 3],
            ['DGP003', 'Dhaval Gordhanbhai Patel', 'ધવલ ગોરધનભાઈ પટેલ', 3],
            ['DPP003', 'Pritesh Dineshbhai Patel', 'પ્રિતેશ દિનેશભાઈ પટેલ', 3],
            ['KPP002', 'Krunal Pravinbhai Patel', 'કૃણાલ પ્રવિણભાઈ પટેલ', 2],
            ['MJP002', 'Mehul Jayantibhai Patel', 'મેહુલ જયંતિભાઈ પટેલ', 2],
            ['RBP002', 'Ramesh Bhagwandas Patel', 'રમેશ ભગવાનદાસ પટેલ', 2],
            ['DGP002', 'Dhaval Ghanshyambhai Patel', 'ધવલ ઘનશ્યામભાઈ પટેલ', 2],
            ['GAP002', 'Girish Arvindbhai Patel', 'ગિરીશ અરવિંદભાઈ પટેલ', 2],
            ['HJP002', 'Hardik Jayantibhai Patel', 'હાર્દિક જયંતિભાઈ પટેલ', 2],
            ['VRP002', 'Veet Rajendrabhai Patel', 'વીત રાજેન્દ્રભાઈ પટેલ', 2],
            ['JAP002', 'Jaimin Atmaram Patel', 'જૈમિન આત્મારામ પટેલ', 2],
            ['CCP002', 'Chetan Chimanbhai Patel', 'ચેતન ચીમનભાઈ પટેલ', 2],
            ['GCP001', 'Gaurang Chimanbhai Patel', 'ગૌરાંગ ચીમનભાઈ પટેલ', 1],
            ['DAP001', 'Darshan Ashokbhai Patel', 'દર્શન અશોકભાઈ પટેલ', 1],
            ['RDP001', 'Rajesh Dineshbhai Patel', 'રાજેશ દિનેશભાઈ પટેલ', 1],
            ['AVP001', 'Ankur Vishnubhai Patel', 'અંકુર વિષ્ણુભાઈ પટેલ', 1],
        ];

        /*
        |--------------------------------------------------------------------------
        | Validate Input
        |--------------------------------------------------------------------------
        */

        $this->validateMembers($members);

        /*
        |--------------------------------------------------------------------------
        | Admin
        |--------------------------------------------------------------------------
        */

        $admin = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$admin) {
            throw new RuntimeException(
                'Active admin not found. Run AdminSeeder first.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Transaction
        |--------------------------------------------------------------------------
        */

        DB::transaction(function () use (
            $members,
            $openingDate,
            $postingDate,
            $admin
        ) {
            foreach ($members as [
                $memberCode,
                $nameEn,
                $nameGu,
                $accountCount
            ]) {
                /*
                |--------------------------------------------------------------------------
                | Member
                |--------------------------------------------------------------------------
                */

                $member = User::query()
                    ->where('member_code', $memberCode)
                    ->lockForUpdate()
                    ->first();

                if (!$member) {
                    $placeholderEmail = strtolower($memberCode)
                        . '@members.invalid';

                    if (
                        User::query()
                            ->where('email', $placeholderEmail)
                            ->exists()
                    ) {
                        throw new RuntimeException(
                            "Email conflict: {$placeholderEmail}"
                        );
                    }

                    $member = User::create([
                        'member_code' => $memberCode,
                        'name' => $nameEn,
                        'name_gu' => $nameGu,
                        'email' => $placeholderEmail,
                        'password' => Hash::make(Str::random(64)),
                        'phone' => null,
                        'address' => null,
                        'role' => 'member',
                        'preferred_language' => 'gu',
                        'is_active' => false,
                    ]);
                } else {
                    if ($member->role !== 'member') {
                        throw new RuntimeException(
                            "Member code conflict: {$memberCode}"
                        );
                    }

                    $member->update([
                        'name' => $nameEn,
                        'name_gu' => $nameGu,
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Society Accounts
                |--------------------------------------------------------------------------
                */

                for ($i = 1; $i <= $accountCount; $i++) {
                    $accountNumber = sprintf(
                        'VF-%s-%02d',
                        $memberCode,
                        $i
                    );

                    $account = SocietyAccount::query()
                        ->where('account_number', $accountNumber)
                        ->lockForUpdate()
                        ->first();

                    if (!$account) {
                        $account = SocietyAccount::create([
                            'user_id' => $member->id,
                            'account_number' => $accountNumber,
                            'monthly_amount' => self::MONTHLY_AMOUNT,
                            'opening_fee_amount' => self::OPENING_FEE,
                            'opened_at' => $openingDate,
                            'status' => 'active',
                        ]);
                    } else {
                        if ((int) $account->user_id !== (int) $member->id) {
                            throw new RuntimeException(
                                "Account owner mismatch: {$accountNumber}"
                            );
                        }

                        if (
                            $this->toPaise($account->monthly_amount) !== 100000
                            ||
                            $this->toPaise($account->opening_fee_amount) !== 10000
                        ) {
                            throw new RuntimeException(
                                "Account financial settings mismatch: {$accountNumber}"
                            );
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Opening Fee
                    |--------------------------------------------------------------------------
                    */

                    $fee = AccountOpeningFee::query()
                        ->where('society_account_id', $account->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$fee) {
                        $fee = AccountOpeningFee::create([
                            'society_account_id' => $account->id,
                            'amount' => self::OPENING_FEE,
                            'status' => 'pending',
                        ]);
                    }

                    if ($this->toPaise($fee->amount) !== 10000) {
                        throw new RuntimeException(
                            "Opening fee amount mismatch: {$accountNumber}"
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Historical Fee Payment
                    |--------------------------------------------------------------------------
                    |
                    | Historical payment is confirmed as paid.
                    | Actual payment method is unknown.
                    | Do not invent cash, UPI or bank.
                    |
                    */

                    if ($fee->status === 'pending') {
                        $fee->update([
                            'status' => 'paid',
                            'paid_at' => $postingDate,
                            'receipt_number' => sprintf(
                                'VF-HOF-%08d',
                                $fee->id
                            ),
                            'payment_method' => null,
                            'payment_reference' => 'HISTORICAL-IMPORT',
                            'received_by' => $admin->id,
                        ]);
                    }

                    if ($fee->status !== 'paid') {
                        throw new RuntimeException(
                            "Unexpected opening fee status: {$accountNumber}"
                        );
                    }

                    if (!$fee->paid_at) {
                        throw new RuntimeException(
                            "Paid date missing: {$accountNumber}"
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Fund Ledger
                    |--------------------------------------------------------------------------
                    */

                    $entry = FundEntry::query()
                        ->where('reference_type', 'opening_fee')
                        ->where('reference_id', $fee->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$entry) {
                        FundEntry::create([
                            'reference_type' => 'opening_fee',
                            'reference_id' => $fee->id,
                            'direction' => 'credit',
                            'amount' => self::OPENING_FEE,
                            'transaction_date' => Carbon::parse(
                                $fee->paid_at
                            )->format('Y-m-d'),
                            'description' => 'Historical opening fee - '
                                . $accountNumber,
                            'created_by' => $admin->id,
                        ]);
                    } else {
                        if (
                            $entry->direction !== 'credit'
                            ||
                            $this->toPaise($entry->amount) !== 10000
                        ) {
                            throw new RuntimeException(
                                "Opening fee ledger mismatch: {$accountNumber}"
                            );
                        }
                    }
                }

                $this->command?->info(
                    "{$memberCode} | {$nameEn} | {$accountCount} accounts"
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Final Verification
            |--------------------------------------------------------------------------
            |
            | Uses direct user_id and society_account_id.
            | No member() relationship required.
            |
            */

            $codes = array_column($members, 0);

            $memberIds = User::query()
                ->whereIn('member_code', $codes)
                ->pluck('id');

            $accountIds = SocietyAccount::query()
                ->whereIn('user_id', $memberIds)
                ->pluck('id');

            $importedMembers = $memberIds->count();
            $importedAccounts = $accountIds->count();

            $importedFees = AccountOpeningFee::query()
                ->whereIn('society_account_id', $accountIds)
                ->where('status', 'paid')
                ->count();

            $feeIds = AccountOpeningFee::query()
                ->whereIn('society_account_id', $accountIds)
                ->pluck('id');

            $ledgerEntries = FundEntry::query()
                ->where('reference_type', 'opening_fee')
                ->whereIn('reference_id', $feeIds)
                ->where('direction', 'credit')
                ->get();

            $ledgerCount = $ledgerEntries->count();

            $ledgerTotalPaise = $ledgerEntries->sum(
                fn ($entry) => $this->toPaise($entry->amount)
            );

            if (
                $importedMembers !== self::EXPECTED_MEMBERS
                ||
                $importedAccounts !== self::EXPECTED_ACCOUNTS
                ||
                $importedFees !== self::EXPECTED_ACCOUNTS
                ||
                $ledgerCount !== self::EXPECTED_ACCOUNTS
                ||
                $ledgerTotalPaise !== 1100000
            ) {
                throw new RuntimeException(
                    'Historical import verification failed. Transaction rolled back.'
                );
            }

            $this->command?->info(
                'Verified: 31 members, 110 accounts, '
                . '110 paid opening fees, Rs. 11,000 ledger credits.'
            );
        });

        $this->command?->info(
            'Historical society members imported successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Dates
    |--------------------------------------------------------------------------
    */

    private function validateDate(?string $date, string $key): void
    {
        if (!$date) {
            throw new RuntimeException(
                "{$key} is required in .env."
            );
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);

            if (
                !$parsed
                ||
                $parsed->format('Y-m-d') !== $date
            ) {
                throw new RuntimeException('Invalid date format.');
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "{$key} must contain a valid YYYY-MM-DD date."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Members
    |--------------------------------------------------------------------------
    */

    private function validateMembers(array $members): void
    {
        if (count($members) !== self::EXPECTED_MEMBERS) {
            throw new RuntimeException(
                'Historical member count must be 31.'
            );
        }

        $codes = [];
        $totalAccounts = 0;

        foreach ($members as $member) {
            if (count($member) !== 4) {
                throw new RuntimeException(
                    'Invalid historical member data structure.'
                );
            }

            [$code, $nameEn, $nameGu, $count] = $member;

            if (
                !is_string($code)
                ||
                !preg_match('/^[A-Z0-9]+$/', $code)
                ||
                trim($nameEn) === ''
                ||
                trim($nameGu) === ''
                ||
                !is_int($count)
                ||
                $count < 1
            ) {
                throw new RuntimeException(
                    "Invalid historical member: {$code}"
                );
            }

            if (in_array($code, $codes, true)) {
                throw new RuntimeException(
                    "Duplicate member code: {$code}"
                );
            }

            $codes[] = $code;
            $totalAccounts += $count;
        }

        if ($totalAccounts !== self::EXPECTED_ACCOUNTS) {
            throw new RuntimeException(
                "Expected 110 accounts, found {$totalAccounts}."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Convert Decimal Money To Paise
    |--------------------------------------------------------------------------
    |
    | Avoid float comparisons for financial amounts.
    |
    */

    private function toPaise(string|int|float $amount): int
    {
        $value = (string) $amount;

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new RuntimeException(
                "Invalid financial amount: {$value}"
            );
        }

        [$rupees, $paise] = array_pad(
            explode('.', $value, 2),
            2,
            '0'
        );

        return ((int) $rupees * 100)
            + (int) str_pad($paise, 2, '0');
    }
}