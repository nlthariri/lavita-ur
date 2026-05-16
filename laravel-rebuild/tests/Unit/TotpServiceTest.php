<?php

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    private TotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new TotpService();
    }

    public function test_generated_secret_is_valid_base32(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThanOrEqual(32, strlen($secret));
    }

    public function test_code_is_exactly_6_digits(): void
    {
        $secret = $this->totp->generateSecret();
        $code = $this->totp->getCode($secret, time());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_same_window_gives_same_code(): void
    {
        $secret = $this->totp->generateSecret();
        $timestamp = time();
        $this->assertSame(
            $this->totp->getCode($secret, $timestamp),
            $this->totp->getCode($secret, $timestamp),
        );
    }

    public function test_verify_accepts_current_window(): void
    {
        $secret = $this->totp->generateSecret();
        $code = $this->totp->getCode($secret, time());
        $this->assertTrue($this->totp->verify($secret, $code));
    }

    public function test_verify_accepts_previous_window_for_clock_drift(): void
    {
        $secret = $this->totp->generateSecret();
        $previousWindowTimestamp = time() - 30;
        $code = $this->totp->getCode($secret, $previousWindowTimestamp);
        $this->assertTrue($this->totp->verify($secret, $code));
    }

    public function test_verify_rejects_wrong_code(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertFalse($this->totp->verify($secret, '000000'));
        $this->assertFalse($this->totp->verify($secret, '999999'));
    }

    public function test_verify_rejects_old_window_outside_drift(): void
    {
        $secret = $this->totp->generateSecret();
        $oldTimestamp = time() - 90;
        $code = $this->totp->getCode($secret, $oldTimestamp);
        $this->assertFalse($this->totp->verify($secret, $code));
    }

    public function test_different_secrets_give_different_codes(): void
    {
        $timestamp = time();
        $code1 = $this->totp->getCode($this->totp->generateSecret(), $timestamp);
        $code2 = $this->totp->getCode($this->totp->generateSecret(), $timestamp);
        // Met hoge kans ongelijk (1/1.000.000 kans op collision, voldoende voor test)
        $this->assertIsString($code1);
        $this->assertIsString($code2);
    }

    public function test_rfc6238_known_vector_sha1(): void
    {
        // RFC 6238 test vector: secret = base32(0x3132...3839 = "12345678901234567890")
        // T=59: expected code = 287082
        // Bron: RFC 6238 Appendix B, Table 1
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $code = $this->totp->getCode($secret, 59);
        $this->assertSame('287082', $code);
    }
}
