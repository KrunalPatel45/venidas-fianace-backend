<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSocietyAccountRequest;
use App\Models\SocietyAccount;
use App\Services\AccountService;
use Illuminate\Http\Request;

class SocietyAccountController extends Controller
{
    public function __construct(
        private AccountService $accountService
    ) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        return SocietyAccount::query()
            ->with([
                'member:id,name,name_gu,member_code',
                'openingFee',
            ])
            ->when($request->filled('user_id'), function ($query) use ($request) {
                $query->where('user_id', $request->query('user_id'));
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('account_number', 'like', "%{$search}%")
                        ->orWhereHas('member', function ($memberQuery) use ($search) {
                            $memberQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('name_gu', 'like', "%{$search}%")
                                ->orWhere('member_code', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate(20);
    }

    public function store(StoreSocietyAccountRequest $request)
    {
        $account = $this->accountService->createAccount(
            $request->validated()
        );

        return response()->json([
            'message' => 'Account created successfully.',
            'data' => $account,
        ], 201);
    }

    public function show(SocietyAccount $account)
    {
        return response()->json([
            'data' => $account->load([
                'member:id,name,name_gu,member_code',
                'openingFee',
            ]),
        ]);
    }

    public function myAccounts(Request $request)
    {
        $accounts = SocietyAccount::query()
            ->where('user_id', $request->user()->id)
            ->with('openingFee')
            ->get();

        return response()->json([
            'data' => $accounts,
            'total_accounts' => $accounts->count(),
            'monthly_investment' => $accounts
                ->where('status', 'active')
                ->sum('monthly_amount'),
        ]);
    }
}