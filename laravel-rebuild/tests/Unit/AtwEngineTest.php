<?php

namespace Tests\Unit;

use App\Services\AtwEngine;
use PHPUnit\Framework\TestCase;

class AtwEngineTest extends TestCase
{
    private AtwEngine $engine;

    private array $defaultPolicy = [
        'daily_max_minutes' => 720,      // 12 uur
        'weekly_max_minutes' => 3600,    // 60 uur
        'weekly_warning_minutes' => 2880, // 48 uur
        'average_16_week_minutes' => 2880, // 48 uur/week gemiddeld
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AtwEngine();
    }

    public function test_no_signals_for_normal_shift(): void
    {
        $proposed = [
            'start_at' => '2026-05-11T08:00:00+00:00',
            'end_at' => '2026-05-11T16:00:00+00:00',
            'net_minutes' => 480,
        ];

        $signals = $this->engine->evaluate($proposed, [], $this->defaultPolicy);
        $this->assertEmpty($signals);
    }

    public function test_daily_limit_critical_when_net_minutes_gte_720(): void
    {
        $proposed = [
            'start_at' => '2026-05-11T06:00:00+00:00',
            'end_at' => '2026-05-11T18:00:00+00:00',
            'net_minutes' => 720,  // precies op de grens
        ];

        $signals = $this->engine->evaluate($proposed, [], $this->defaultPolicy);
        $types = array_column($signals, 'type');
        $this->assertContains('DAILY_LIMIT', $types);

        $dailySignal = array_filter($signals, fn ($s) => $s['type'] === 'DAILY_LIMIT');
        $this->assertSame('critical', reset($dailySignal)['severity']);
    }

    public function test_weekly_warning_when_total_between_2880_and_3600(): void
    {
        // Bestaande diensten: maandag t/m donderdag, elk 480 min = 1920 min
        // Voorgestelde dienst: vrijdag 480 + 480 (extra existing) = scenario voor warning
        // Makkelijker: existing week total = 2880 - 1 min, proposed = 480 => total = 3359 (warning maar < 3600)
        $weekStart = '2026-05-11'; // maandag
        $existing = [];
        // Voeg 4 x 480 min toe in dezelfde week = 1920 min
        for ($i = 0; $i < 4; $i++) {
            $start = '2026-05-1'.($i + 1).'T08:00:00+00:00';
            $end = '2026-05-1'.($i + 1).'T16:00:00+00:00';
            $existing[] = ['id' => $i + 1, 'start_at' => $start, 'end_at' => $end, 'net_minutes' => 480];
        }

        // Proposed vrijdag 480 min -> week total = 1920 + 480 = 2400 (< 2880 nog geen warning)
        // Laat me een scenario bouwen met meer bestaande uren
        $existing2 = [];
        for ($i = 0; $i < 6; $i++) {
            $day = 11 + $i;
            $existing2[] = [
                'id' => $i + 1,
                'start_at' => "2026-05-{$day}T08:00:00+00:00",
                'end_at' => "2026-05-{$day}T16:00:00+00:00",
                'net_minutes' => 480,
            ];
        }

        $proposed = [
            'start_at' => '2026-05-14T08:00:00+00:00', // donderdag in week mei 11
            'end_at' => '2026-05-14T12:00:00+00:00',
            'net_minutes' => 240,
        ];
        // week mei 11 (ma) t/m 17 (zo): existing[0]=ma, existing[1]=di, existing[2]=wo = 3x480 = 1440
        // proposed do = 240 => week total = 1680 (nog geen warning)

        // Gebruik hogere waarden: 5 bestaande diensten van 600 min in dezelfde week
        $highExisting = [];
        for ($i = 0; $i < 4; $i++) {
            $day = 11 + $i;
            $highExisting[] = [
                'id' => $i + 1,
                'start_at' => "2026-05-{$day}T08:00:00+00:00",
                'end_at' => "2026-05-{$day}T19:00:00+00:00",
                'net_minutes' => 600,
            ];
        }
        // 4 x 600 = 2400, proposed = 600 => total = 3000 → warning (2880 ≤ 3000 < 3600)
        $proposedWarning = [
            'start_at' => '2026-05-15T08:00:00+00:00',
            'end_at' => '2026-05-15T19:00:00+00:00',
            'net_minutes' => 600,
        ];

        $signals = $this->engine->evaluate($proposedWarning, $highExisting, $this->defaultPolicy);
        $types = array_column($signals, 'type');
        $this->assertContains('WEEKLY_WARNING', $types);
        $this->assertNotContains('WEEKLY_LIMIT', $types);
    }

    public function test_weekly_limit_critical_when_total_gte_3600(): void
    {
        $existing = [];
        for ($i = 0; $i < 5; $i++) {
            $day = 11 + $i;
            $existing[] = [
                'id' => $i + 1,
                'start_at' => "2026-05-{$day}T08:00:00+00:00",
                'end_at' => "2026-05-{$day}T20:00:00+00:00",
                'net_minutes' => 700,
            ];
        }
        // 5 x 700 = 3500, proposed = 700 => total = 4200 → limit
        $proposed = [
            'start_at' => '2026-05-16T08:00:00+00:00',
            'end_at' => '2026-05-16T20:00:00+00:00',
            'net_minutes' => 700,
        ];

        $signals = $this->engine->evaluate($proposed, $existing, $this->defaultPolicy);
        $types = array_column($signals, 'type');
        $this->assertContains('WEEKLY_LIMIT', $types);
        $weeklySignal = array_filter($signals, fn ($s) => $s['type'] === 'WEEKLY_LIMIT');
        $this->assertSame('critical', reset($weeklySignal)['severity']);
    }

    public function test_rest_period_critical_when_less_than_11_hours(): void
    {
        // Vorige dienst eindigt 22:00, nieuwe start 06:00 = 8 uur rust (< 11)
        $existing = [[
            'id' => 1,
            'start_at' => '2026-05-11T14:00:00+00:00',
            'end_at' => '2026-05-11T22:00:00+00:00',
            'net_minutes' => 480,
        ]];

        $proposed = [
            'start_at' => '2026-05-12T06:00:00+00:00',
            'end_at' => '2026-05-12T14:00:00+00:00',
            'net_minutes' => 480,
        ];

        $signals = $this->engine->evaluate($proposed, $existing, $this->defaultPolicy);
        $types = array_column($signals, 'type');
        $this->assertContains('REST_PERIOD', $types);
        $restSignal = array_filter($signals, fn ($s) => $s['type'] === 'REST_PERIOD');
        $restSignal = reset($restSignal);
        $this->assertSame('critical', $restSignal['severity']);
        $this->assertSame(480, $restSignal['current_minutes']); // 8 uur = 480 min
        $this->assertSame(660, $restSignal['threshold_minutes']);
    }

    public function test_sufficient_rest_produces_no_rest_signal(): void
    {
        $existing = [[
            'id' => 1,
            'start_at' => '2026-05-11T07:00:00+00:00',
            'end_at' => '2026-05-11T15:00:00+00:00',
            'net_minutes' => 480,
        ]];

        $proposed = [
            'start_at' => '2026-05-12T08:00:00+00:00', // 17 uur rust > 11 uur
            'end_at' => '2026-05-12T16:00:00+00:00',
            'net_minutes' => 480,
        ];

        $signals = $this->engine->evaluate($proposed, $existing, $this->defaultPolicy);
        $types = array_column($signals, 'type');
        $this->assertNotContains('REST_PERIOD', $types);
    }

    public function test_sixteen_week_average_uses_full_week_window(): void
    {
        $proposed = [
            'start_at' => '2026-05-20T08:00:00+00:00',
            'end_at' => '2026-05-20T20:00:00+00:00',
            'net_minutes' => 2880,
        ];

        $existing = [];

        // Vul 15 weken in het venster met exact 2880 min per week.
        $windowStart = new \DateTimeImmutable('2026-02-02T08:00:00+00:00'); // maandag van weekvenster
        for ($i = 0; $i < 15; $i++) {
            $start = $windowStart->modify('+'.$i.' weeks')->format('Y-m-d\\TH:i:sP');
            $end = $windowStart->modify('+'.$i.' weeks')->modify('+12 hours')->format('Y-m-d\\TH:i:sP');
            $existing[] = [
                'id' => $i + 1,
                'start_at' => $start,
                'end_at' => $end,
                'net_minutes' => 2880,
            ];
        }

        // Buiten venster (mag niet meetellen).
        $existing[] = [
            'id' => 999,
            'start_at' => '2026-01-26T08:00:00+00:00',
            'end_at' => '2026-01-26T20:00:00+00:00',
            'net_minutes' => 6000,
        ];

        $signals = $this->engine->evaluate($proposed, $existing, $this->defaultPolicy);
        $types = array_column($signals, 'type');

        $this->assertContains('SIXTEEN_WEEK_AVERAGE', $types);
    }
}
