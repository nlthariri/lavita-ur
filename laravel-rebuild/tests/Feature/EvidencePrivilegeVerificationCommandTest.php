<?php

namespace Tests\Feature;

use App\Models\SystemJobRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EvidencePrivilegeVerificationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_privilege_verification_records_job_run_in_sqlite_as_not_applicable(): void
    {
        $this->artisan('integrity:evidence-privileges:verify')
            ->assertExitCode(0);

        $job = SystemJobRun::where('job_name', 'integrity.evidence_privilege_check')->firstOrFail();
        $this->assertSame('completed', $job->status);
        $this->assertSame('sqlite', $job->details['driver']);
        $this->assertSame('not_applicable', $job->details['status']);
        $this->assertSame(0, $job->details['violations_count']);
    }

    public function test_privilege_verification_lock_blocks_parallel_run(): void
    {
        $lock = Cache::lock('integrity:evidence-privileges:verify:any', 1800);
        $this->assertTrue($lock->get());

        $this->artisan('integrity:evidence-privileges:verify')
            ->assertExitCode(1);

        $lock->release();
        $this->assertSame(0, SystemJobRun::where('job_name', 'integrity.evidence_privilege_check')->count());
    }
}
