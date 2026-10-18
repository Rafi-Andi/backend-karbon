<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Monitoring akses juri: /monitoring (proteksi token) + pencatatan
 * otomatis request API via middleware LogApiAccess.
 */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-ujian-rahasia';

    private function enableMonitoring(): void
    {
        config(['services.monitoring.token' => self::TOKEN]);
    }

    public function test_monitoring_page_requires_valid_token(): void
    {
        $this->enableMonitoring();

        $this->get('/monitoring')->assertStatus(404);
        $this->get('/monitoring?token=salah')->assertStatus(404);
        $this->get('/monitoring?token='.self::TOKEN)
            ->assertOk()
            ->assertSee('Monitoring Akses');
    }

    public function test_monitoring_disabled_when_token_empty(): void
    {
        config(['services.monitoring.token' => null]);

        $this->get('/monitoring?token=apa-saja')->assertStatus(404);
    }

    public function test_api_requests_are_logged_with_user_identity(): void
    {
        $user = User::factory()->create(['role' => 'warga', 'name' => 'Warga Uji']);
        $token = $user->createToken('uji')->plainTextToken;

        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $log = AccessLog::where('path', '/api/user')->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('Warga Uji', $log->user_name);
        $this->assertSame('warga', $log->user_role);
        $this->assertSame('GET', $log->method);
        $this->assertSame(200, $log->status);
        $this->assertNotNull($log->created_at);
    }

    public function test_guest_requests_are_logged_without_user(): void
    {
        $this->getJson('/api/donation-campaigns')->assertOk();

        $log = AccessLog::where('path', '/api/donation-campaigns')->first();

        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
        $this->assertNull($log->user_name);
        $this->assertSame('donations.campaigns', $log->route);
    }

    public function test_web_routes_are_not_logged(): void
    {
        $this->enableMonitoring();

        $this->get('/monitoring?token='.self::TOKEN)->assertOk();

        $this->assertSame(0, AccessLog::count());
    }

    public function test_monitoring_page_shows_logs_with_friendly_labels(): void
    {
        $this->enableMonitoring();

        AccessLog::create([
            'user_name' => 'Rafi',
            'user_role' => 'admin',
            'method' => 'POST',
            'path' => '/api/missions/verify-waste',
            'route' => 'missions.verify-waste',
            'status' => 200,
            'ip' => '10.1.2.3',
            'user_agent' => 'Dart/3.11 (dart:io)',
            'created_at' => now(),
        ]);

        $this->get('/monitoring?token='.self::TOKEN)
            ->assertOk()
            ->assertSee('Rafi')
            ->assertSee('admin')
            ->assertSee('Verifikasi foto sampah')
            ->assertSee('Aplikasi')
            ->assertSee('10.1.2.3');
    }
}
