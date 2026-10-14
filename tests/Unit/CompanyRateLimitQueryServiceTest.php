<?php

namespace Tests\Unit;

use App\Infrastructure\Repositories\AppConfig\CompanyRateLimitRepository;
use App\Services\AppConfig\CompanyRateLimitQueryService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class CompanyRateLimitQueryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_response_delay_is_not_applied_to_a_current_paid_subscription(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13'));
        $service = $this->makeService((object) [
            'response_delay_enabled' => true,
            'response_delay_ms' => 3000,
            'subscription_status' => 'ACTIVE',
            'billing_cycle' => 'MONTHLY',
            'starts_at' => '2026-09-13',
            'current_period_starts_at' => '2026-10-01',
            'current_period_ends_at' => '2026-10-31',
        ]);

        $this->assertSame([
            'is_enabled' => true,
            'delay_ms' => 3000,
            'has_active_subscription' => true,
        ], $service->resolveResponseDelay(10));
    }

    public function test_response_delay_applies_to_an_expired_subscription(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13'));
        $service = $this->makeService((object) [
            'response_delay_enabled' => true,
            'response_delay_ms' => 3000,
            'subscription_status' => 'ACTIVE',
            'billing_cycle' => 'ANNUAL',
            'starts_at' => '2025-10-01',
            'current_period_starts_at' => '2025-10-01',
            'current_period_ends_at' => '2026-10-12',
        ]);

        $this->assertSame([
            'is_enabled' => true,
            'delay_ms' => 3000,
            'has_active_subscription' => false,
        ], $service->resolveResponseDelay(10));
    }

    public function test_delay_settings_default_to_disabled_when_no_row_exists(): void
    {
        $service = $this->makeService(null);

        $this->assertSame([
            'is_enabled' => false,
            'delay_ms' => 0,
            'has_active_subscription' => false,
        ], $service->resolveResponseDelay(10));
    }

    private function makeService(?object $settings): CompanyRateLimitQueryService
    {
        $repository = Mockery::mock(CompanyRateLimitRepository::class);
        $repository->shouldReceive('findCompanyResponseDelaySettings')
            ->once()
            ->with(10)
            ->andReturn($settings);

        return new CompanyRateLimitQueryService($repository);
    }
}
