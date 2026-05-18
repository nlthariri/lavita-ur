<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AtwModuleContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $employee;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Test BV',
            'atw_daily_max_minutes' => 720,
            'atw_weekly_max_minutes' => 3600,
            'atw_weekly_warning_minutes' => 2880,
            'atw_average_16_week_minutes' => 2880,
        ]);

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'own@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'owner', 'is_active' => true,
        ]);

        $this->employee = User::create([
            'name' => 'Employee', 'email' => 'emp@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'employee', 'is_active' => true,
        ]);

        $this->token = $this->createBearerToken($this->owner);
    }

    public function test_validate_atw_returns_no_signals_for_normal_shift(): void
    {
        $response = $this->postJson('/api/internal/work-entries/validate-atw', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'pause_minutes' => 30,
        ], ['Authorization' => 'Bearer '.$this->token]);

        $response->assertStatus(200)
            ->assertJsonPath('has_critical', false)
            ->assertJsonPath('employee_id', $this->employee->id)
            ->assertJsonStructure(['employee_id', 'entry_date', 'net_minutes', 'signals', 'has_critical']);

        $this->assertSame(450, $response->json('net_minutes'));
        $this->assertEmpty($response->json('signals'));
    }

    public function test_validate_atw_detects_daily_limit_violation(): void
    {
        $response = $this->postJson('/api/internal/work-entries/validate-atw', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '06:00',
            'end_time' => '18:00',  // 12 uur netto = 720 min — daglimiet
            'pause_minutes' => 0,
        ], ['Authorization' => 'Bearer '.$this->token]);

        $response->assertStatus(200)
            ->assertJsonPath('has_critical', true);

        $signals = $response->json('signals');
        $signalTypes = array_column($signals, 'type');
        $this->assertContains('DAILY_LIMIT', $signalTypes);

        // Elk signaal heeft een `code`-key (mag null zijn voor non-blocking signalen)
        foreach ($signals as $signal) {
            $this->assertArrayHasKey('code', $signal);
        }

        // DAILY_LIMIT signaal moet API-foutcode `ATW_DAILY_MAX_EXCEEDED` exposen
        $dailyLimitSignal = collect($signals)->firstWhere('type', 'DAILY_LIMIT');
        $this->assertNotNull($dailyLimitSignal);
        $this->assertSame('ATW_DAILY_MAX_EXCEEDED', $dailyLimitSignal['code']);
    }

    public function test_validate_atw_requires_all_mandatory_fields(): void
    {
        $response = $this->postJson('/api/internal/work-entries/validate-atw', [], ['Authorization' => 'Bearer '.$this->token]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id', 'entry_date', 'start_time', 'end_time', 'pause_minutes']);
    }

    public function test_get_atw_signals_returns_empty_when_no_violations(): void
    {
        $response = $this->getJson('/api/internal/atw/signals?user_id='.$this->employee->id, ['Authorization' => 'Bearer '.$this->token]);

        $response->assertStatus(200)
            ->assertJsonPath('count', 0)
            ->assertJsonStructure(['data', 'count']);
    }

    public function test_validate_atw_detects_rest_period_violation(): void
    {
        // Vorige dienst opgeslagen in database
        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-05-14',
            'start_at' => '2026-05-14 14:00:00',
            'end_at' => '2026-05-14 22:00:00',
            'pause_minutes' => 0,
            'net_minutes' => 480,
            'is_finalized' => true,
        ]);

        // Nieuwe dienst 8 uur later — rustperiode = 8 uur (< 11 uur)
        $response = $this->postJson('/api/internal/work-entries/validate-atw', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '06:00',
            'end_time' => '14:00',
            'pause_minutes' => 0,
        ], ['Authorization' => 'Bearer '.$this->token]);

        $response->assertStatus(200)
            ->assertJsonPath('has_critical', true);

        $signals = $response->json('signals');
        $signalTypes = array_column($signals, 'type');
        $this->assertContains('REST_PERIOD', $signalTypes);

        // Elk signaal heeft een `code`-key (mag null zijn voor non-blocking signalen)
        foreach ($signals as $signal) {
            $this->assertArrayHasKey('code', $signal);
        }

        // REST_PERIOD signaal moet API-foutcode `ATW_REST_PERIOD_VIOLATED` exposen
        $restPeriodSignal = collect($signals)->firstWhere('type', 'REST_PERIOD');
        $this->assertNotNull($restPeriodSignal);
        $this->assertSame('ATW_REST_PERIOD_VIOLATED', $restPeriodSignal['code']);
    }

    public function test_employee_cannot_validate_atw_for_other_employee(): void
    {
        $otherEmployee = User::create([
            'name' => 'Other', 'email' => 'other@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'employee', 'is_active' => true,
        ]);

        $employeeToken = $this->createBearerToken($this->employee);

        $response = $this->postJson('/api/internal/work-entries/validate-atw', [
            'employee_id' => $otherEmployee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'pause_minutes' => 30,
        ], ['Authorization' => 'Bearer '.$employeeToken]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.employee_id.0', 'U mag alleen uw eigen ATW-gegevens opvragen.');
    }

    public function test_owner_cannot_validate_atw_for_other_organization_employee(): void
    {
        $otherOrg = Organization::create(['name' => 'Other Org']);
        $otherEmployee = User::create([
            'name' => 'Other Org Emp', 'email' => 'other-org@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $otherOrg->id, 'role' => 'employee', 'is_active' => true,
        ]);

        $response = $this->postJson('/api/internal/work-entries/validate-atw', [
            'employee_id' => $otherEmployee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'pause_minutes' => 30,
        ], ['Authorization' => 'Bearer '.$this->token]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.employee_id.0', 'Medewerker behoort niet tot uw organisatie.');
    }

    public function test_employee_cannot_fetch_other_users_atw_signals(): void
    {
        $otherEmployee = User::create([
            'name' => 'Signal Other', 'email' => 'signal-other@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'employee', 'is_active' => true,
        ]);

        $employeeToken = $this->createBearerToken($this->employee);
        $response = $this->getJson('/api/internal/atw/signals?user_id='.$otherEmployee->id, ['Authorization' => 'Bearer '.$employeeToken]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.employee_id.0', 'U mag alleen uw eigen ATW-gegevens opvragen.');
    }
}
