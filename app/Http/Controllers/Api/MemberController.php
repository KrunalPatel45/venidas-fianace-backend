<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        return User::query()
            ->where('role', 'member')
            ->withCount('societyAccounts')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                    ->orWhere('member_code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('society_accounts_count')
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function store(StoreMemberRequest $request)
    {
        $member = DB::transaction(function () use ($request) {

            $data = $request->validated();

            $member = User::create([
                ...$data,
                'role' => 'member',
                'is_active' => true,
            ]);

            $member->update([
                'member_code' => 'VF-M-'.str_pad(
                    (string) $member->id,
                    5,
                    '0',
                    STR_PAD_LEFT
                ),
            ]);

            return $member;
        });

        return response()->json([
            'message' => 'Member created successfully.',
            'data' => $member,
        ], 201);
    }

    public function show(User $member)
    {
        abort_unless($member->role === 'member', 404);

        return response()->json([
            'data' => $member->load([
                'societyAccounts.openingFee',
            ]),
        ]);
    }

    public function update(
        UpdateMemberRequest $request,
        User $member
    ) {
        abort_unless($member->role === 'member', 404);

        $member->update($request->validated());

        if (!$member->is_active) {
            $member->tokens()->delete();
        }

        return response()->json([
            'message' => 'Member updated successfully.',
            'data' => $member->fresh(),
        ]);
    }

    public function destroy(User $member)
    {
        abort_unless($member->role === 'member', 404);

        $member->update([
            'is_active' => false,
        ]);

        $member->tokens()->delete();

        return response()->json([
            'message' => 'Member deactivated successfully.',
        ]);
    }
}