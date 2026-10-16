<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SEED_ADMIN_EMAIL');
        $password = env('SEED_ADMIN_PASSWORD');

        if (!$email || !$password || strlen($password) < 12) {
            throw new RuntimeException(
                'Set SEED_ADMIN_EMAIL and a password of at least 12 characters.'
            );
        }

        User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'VENIDAS Admin',
                'password' => Hash::make($password),
                'role' => 'admin',
                'preferred_language' => 'gu',
                'is_active' => true,
            ]
        );
    }
}