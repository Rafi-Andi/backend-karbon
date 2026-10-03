<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(array $over = []): array
    {
        return array_merge([
            'name' => 'Moch. Rafi Andi',
            'phone' => '+6281200001111',
            'email' => 'rafi@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
            'city' => 'Surabaya',
            'district' => 'Gubeng',
            'sub_district' => 'Mojo',
            'rt' => '005',
            'rw' => '02',
        ], $over);
    }

    public function test_register_creates_warga_with_token(): void
    {
        $response = $this->postJson('/api/auth/register', $this->basePayload())
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'warga');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('users', ['email' => 'rafi@example.com', 'role' => 'warga']);
        $this->assertDatabaseHas('warga_profiles', ['user_id' => $response->json('data.user.id')]);
    }

    public function test_register_ignores_role_mitra(): void
    {
        // Pendaftaran mitra hanya lewat /api/auth/register-mitra.
        // Field role di endpoint ini diabaikan: tetap jadi warga.
        $response = $this->postJson('/api/auth/register', $this->basePayload(['role' => 'mitra']))
            ->assertStatus(201)
            ->assertJsonPath('data.user.role', 'warga');

        $this->assertDatabaseHas('users', ['email' => 'rafi@example.com', 'role' => 'warga']);
        $this->assertDatabaseMissing('mitra_profiles', ['user_id' => $response->json('data.user.id')]);
    }

    public function test_register_duplicate_email_fails(): void
    {
        User::factory()->create(['email' => 'rafi@example.com']);

        $this->postJson('/api/auth/register', $this->basePayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This email is already registered.');
    }

    public function test_register_invalid_phone_fails(): void
    {
        $this->postJson('/api/auth/register', $this->basePayload(['phone' => '08123456789']))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.phone.0', 'Phone number must be in international format (e.g. +628123456789).');
    }

    public function test_register_missing_fields_fails(): void
    {
        $this->postJson('/api/auth/register', ['name' => '', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_register_password_must_be_confirmed(): void
    {
        $this->postJson('/api/auth/register', $this->basePayload(['password_confirmation' => 'Beda12345!']))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Password confirmation does not match.');
    }
}
