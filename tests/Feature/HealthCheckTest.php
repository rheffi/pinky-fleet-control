<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_reports_a_successful_database_query(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['1' => 1]]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'connected')
            ->assertJsonStructure(['checked_at']);
    }

    public function test_database_failure_returns_503_without_exception_details(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new RuntimeException('private-database-detail'));

        $response = $this->getJson('/api/health');
        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('database', 'unavailable')
            ->assertDontSee('private-database-detail');
    }
}
