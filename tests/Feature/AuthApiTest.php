<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => 'StrongPassword123!',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'admin@example.test',
            'password' => 'StrongPassword123!',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'token_type',
                'user',
            ]);
    }

    public function test_invalid_password_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'member@example.test',
            'password' => 'CorrectPassword123!',
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'member@example.test',
            'password' => 'WrongPassword',
        ])->assertUnprocessable();
    }

    public function test_member_cannot_access_admin_dashboard(): void
    {
        $member = User::factory()->create([
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();
    }

    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }
}