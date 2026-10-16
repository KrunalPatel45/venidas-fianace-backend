<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanBorrowerController extends Controller
{
    public function index(
        Request $request
    ): JsonResponse {
        $validated = $request->validate([
            'loan_type' => [
                'required',
                Rule::in([
                    'member',
                    'external',
                ]),
            ],
            'search' => [
                'nullable',
                'string',
                'max:150',
            ],
            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        $query = User::query()
            ->where(
                'role',
                $validated['loan_type']
            )
            ->where(
                'is_active',
                true
            );

        $search = trim(
            $validated['search'] ?? ''
        );

        if ($search !== '') {
            $query->where(
                function ($q) use ($search) {
                    $q->where(
                        'name',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'name_gu',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'member_code',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'email',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'phone',
                        'like',
                        "%{$search}%"
                    );
                }
            );
        }

        $borrowers = $query
            ->select([
                'id',
                'member_code',
                'name',
                'name_gu',
                'email',
                'phone',
                'address',
                'role',
                'is_active',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $borrowers,
        ]);
    }
}