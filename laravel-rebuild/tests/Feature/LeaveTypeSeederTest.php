<?php

namespace Tests\Feature;

use App\Models\LeaveType;
use App\Models\Organization;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveTypeSeederTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org1;

    private Organization $org2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org1 = Organization::create(['name' => 'Org One']);
        $this->org2 = Organization::create(['name' => 'Org Two']);
    }

    public function test_creates_4_default_leave_types_for_each_organization(): void
    {
        (new LeaveTypeSeeder())->run();

        $this->assertSame(4, LeaveType::where('organization_id', $this->org1->id)->count());
        $this->assertSame(4, LeaveType::where('organization_id', $this->org2->id)->count());
    }

    public function test_creates_leave_types_with_correct_codes_and_settings(): void
    {
        (new LeaveTypeSeeder())->run();

        $vakantie = LeaveType::where('organization_id', $this->org1->id)
            ->where('code', 'VAKANTIE')->first();
        $bijzonder = LeaveType::where('organization_id', $this->org1->id)
            ->where('code', 'BIJZONDER')->first();
        $onbetaald = LeaveType::where('organization_id', $this->org1->id)
            ->where('code', 'ONBETAALD')->first();
        $ouderschap = LeaveType::where('organization_id', $this->org1->id)
            ->where('code', 'OUDERSCHAP')->first();

        // VAKANTIE counts towards balance
        $this->assertNotNull($vakantie);
        $this->assertSame('Vakantieverlof', $vakantie->name);
        $this->assertTrue($vakantie->counts_towards_balance);
        $this->assertNull($vakantie->max_days_per_year);
        $this->assertTrue($vakantie->is_active);

        // BIJZONDER does NOT count towards balance
        $this->assertNotNull($bijzonder);
        $this->assertSame('Bijzonder verlof', $bijzonder->name);
        $this->assertFalse($bijzonder->counts_towards_balance);

        // ONBETAALD does NOT count towards balance
        $this->assertNotNull($onbetaald);
        $this->assertSame('Onbetaald verlof', $onbetaald->name);
        $this->assertFalse($onbetaald->counts_towards_balance);

        // OUDERSCHAP does NOT count towards balance
        $this->assertNotNull($ouderschap);
        $this->assertSame('Ouderschapsverlof', $ouderschap->name);
        $this->assertFalse($ouderschap->counts_towards_balance);
    }

    public function test_is_idempotent_and_does_not_create_duplicates(): void
    {
        // Run seeder twice
        (new LeaveTypeSeeder())->run();
        (new LeaveTypeSeeder())->run();

        // Should still have exactly 4 leave types per org
        $this->assertSame(4, LeaveType::where('organization_id', $this->org1->id)->count());
        $this->assertSame(4, LeaveType::where('organization_id', $this->org2->id)->count());
    }

    public function test_does_not_overwrite_existing_customizations(): void
    {
        // Run seeder first time
        (new LeaveTypeSeeder())->run();

        // Admin customizes the name
        $vakantie = LeaveType::where('organization_id', $this->org1->id)
            ->where('code', 'VAKANTIE')->first();
        $vakantie->update(['name' => 'Aangepast vakantieverlof']);

        // Run seeder again
        (new LeaveTypeSeeder())->run();

        // Custom name should be preserved (firstOrCreate doesn't overwrite)
        $vakantie->refresh();
        $this->assertSame('Aangepast vakantieverlof', $vakantie->name);
    }
}
