<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExternalBorrowerRequest;
use App\Http\Requests\UpdateExternalBorrowerRequest;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExternalBorrowerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = User::query()
            ->where('role', 'external');

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('name_gu', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('member_code', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', $validated['is_active']);
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
                'preferred_language',
                'is_active',
                'created_at',
            ])
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $borrowers,
        ]);
    }

    public function store(
        StoreExternalBorrowerRequest $request
    ): JsonResponse {
        $data = $request->validated();

        $borrower = DB::transaction(function () use ($data) {
            /*
             * Generate a unique borrower code.
             * The database unique constraint is the final safeguard.
             */
            do {
                $code = 'EXT-' . strtoupper(Str::random(12));
            } while (
                User::query()
                    ->where('member_code', $code)
                    ->exists()
            );

            /*
             * users.email may be NOT NULL in the existing schema.
             * A reserved .invalid placeholder is used when no
             * real email is supplied.
             */
            $email = $data['email'] ?? null;

            if (!$email) {
                $email = strtolower($code) . '@borrowers.invalid';
            }

            return User::create([
                'member_code' => $code,
                'name' => $data['name'],
                'name_gu' => $data['name_gu'] ?? null,
                'email' => $email,
                'phone' => $data['phone'],
                'address' => $data['address'] ?? null,
                'preferred_language' =>
                    $data['preferred_language'] ?? 'gu',
                'role' => 'external',
                'is_active' => false,
                'password' => Hash::make(Str::random(64)),
            ]);
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'External borrower created successfully.',
            'data' => $this->borrowerData($borrower),
        ], 201);
    }

    public function show(User $externalBorrower): JsonResponse
    {
        $this->ensureExternal($externalBorrower);

        $loans = Loan::query()
            ->where('borrower_id', $externalBorrower->id)
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => [
                'borrower' => $this->borrowerData($externalBorrower),
                'loans' => $loans,
            ],
        ]);
    }

    public function update(
        UpdateExternalBorrowerRequest $request,
        User $externalBorrower
    ): JsonResponse {
        $this->ensureExternal($externalBorrower);

        $externalBorrower->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'External borrower updated successfully.',
            'data' => $this->borrowerData(
                $externalBorrower->fresh()
            ),
        ]);
    }

    /**
     * Deactivate borrower instead of deleting financial history.
     */
    public function deactivate(User $externalBorrower): JsonResponse
    {
        $this->ensureExternal($externalBorrower);

        DB::transaction(function () use ($externalBorrower) {
            $borrower = User::query()
                ->whereKey($externalBorrower->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasOpenLoan = Loan::query()
                ->where('borrower_id', $borrower->id)
                ->where(function ($query) {
                    $query->where('status', 'active')
                        ->orWhere('outstanding_principal', '>', 0);
                })
                ->exists();

            if ($hasOpenLoan) {
                throw ValidationException::withMessages([
                    'borrower' =>
                        'Borrower has an active or outstanding loan.',
                ]);
            }

            /*
             * Also block deactivation if any interest is unpaid,
             * including interest on already closed loans.
             */
            $hasPendingInterest = DB::table('loan_interest_dues')
                ->join(
                    'loans',
                    'loans.id',
                    '=',
                    'loan_interest_dues.loan_id'
                )
                ->where('loans.borrower_id', $borrower->id)
                ->where('loan_interest_dues.status', 'pending')
                ->exists();

            if ($hasPendingInterest) {
                throw ValidationException::withMessages([
                    'borrower' => 'Borrower has unpaid loan interest.',
                ]);
            }

            $borrower->update([
                'is_active' => false,
            ]);

            // Revoke all existing Sanctum tokens.
            $borrower->tokens()->delete();
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'External borrower deactivated.',
        ]);
    }

    private function ensureExternal(User $borrower): void
    {
        abort_unless($borrower->role === 'external', 404);
    }

    private function borrowerData(User $borrower): array
    {
        return $borrower->only([
            'id',
            'member_code',
            'name',
            'name_gu',
            'email',
            'phone',
            'address',
            'preferred_language',
            'role',
            'is_active',
            'created_at',
        ]);
    }

    
    public function activate(User $externalBorrower): JsonResponse
    {
        $this->ensureExternal($externalBorrower);

        $borrower = DB::transaction(function () use ($externalBorrower) {
            $locked = User::query()
                ->whereKey($externalBorrower->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$locked->phone) {
                throw ValidationException::withMessages([
                    'phone' => 'Borrower phone number is required.',
                ]);
            }

            $locked->update([
                'is_active' => true,
            ]);

            return $locked->fresh();
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'External borrower activated.',
            'data' => $this->borrowerData($borrower),
        ]);
    }

}
