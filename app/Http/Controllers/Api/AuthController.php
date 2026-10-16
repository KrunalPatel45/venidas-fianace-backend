<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', $validated['email'])
            ->first();

        if (
            !$user ||
            !Hash::check($validated['password'], $user->password)
        ) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your account is inactive.'],
            ]);
        }

        $token = $user->createToken('venidas-web')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'token' => $token,
                'user' => $this->userData($user),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->userData($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()
            ->currentAccessToken()
            ?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful.',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $user = $request->user();

        if (!Hash::check(
            $validated['current_password'],
            $user->password
        )) {
            throw ValidationException::withMessages([
                'current_password' => [
                    'Current password is incorrect.',
                ],
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password changed. Please login again.',
        ]);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'member_code' => $user->member_code,
            'name' => $user->name,
            'name_gu' => $user->name_gu,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'preferred_language' => $user->preferred_language,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
