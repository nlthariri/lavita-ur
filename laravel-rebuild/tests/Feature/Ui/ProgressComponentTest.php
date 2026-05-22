<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Smoke-test voor task 1.3 — `<x-ui.progress>` Blade-component.
 *
 * Valideert:
 *   - requirements.md 12.3, 12.8, 12.10
 *   - design.md § `<x-ui.progress>` — breedte-berekening, variant-kleuren, ARIA
 *   - Property 10: Progress-bar breedte is proportioneel aan value/max
 */
final class ProgressComponentTest extends TestCase
{
    public function test_progress_renders_with_correct_aria_attributes(): void
    {
        $html = Blade::render(
            '<x-ui.progress :value="32" :max="40" variant="success" label="Uren deze week" />'
        );

        // ARIA progressbar role — Requirement 12.10
        self::assertStringContainsString('role="progressbar"', $html);
        self::assertStringContainsString('aria-valuenow="32"', $html);
        self::assertStringContainsString('aria-valuemin="0"', $html);
        self::assertStringContainsString('aria-valuemax="40"', $html);
        self::assertStringContainsString('aria-label="Uren deze week"', $html);
    }

    public function test_progress_renders_label_and_percentage(): void
    {
        $html = Blade::render(
            '<x-ui.progress :value="50" :max="100" variant="success" label="Voortgang" />'
        );

        // Label tekst
        self::assertStringContainsString('Voortgang', $html);
        // Percentage weergave (50%)
        self::assertStringContainsString('50%', $html);
    }

    public function test_progress_hides_percentage_when_show_percentage_is_false(): void
    {
        $html = Blade::render(
            '<x-ui.progress :value="50" :max="100" variant="success" label="Test" :showPercentage="false" />'
        );

        // Label still shows
        self::assertStringContainsString('Test', $html);
        // Percentage text span (class="text-steel") should NOT be rendered
        self::assertStringNotContainsString('text-steel', $html);
    }

    public function test_progress_width_calculation_is_correct(): void
    {
        // 32/40 = 80%
        $html = Blade::render(
            '<x-ui.progress :value="32" :max="40" variant="success" label="Test" />'
        );

        self::assertStringContainsString('width: 80%', $html);
        self::assertStringContainsString('80%', $html);
    }

    public function test_progress_width_clamps_to_zero_for_negative_values(): void
    {
        $html = Blade::render(
            '<x-ui.progress :value="-5" :max="100" variant="success" label="Test" />'
        );

        self::assertStringContainsString('width: 0%', $html);
    }

    public function test_progress_width_clamps_to_100_for_overflow_values(): void
    {
        $html = Blade::render(
            '<x-ui.progress :value="150" :max="100" variant="success" label="Test" />'
        );

        self::assertStringContainsString('width: 100%', $html);
    }

    public function test_progress_variant_success_uses_brand_green(): void
    {
        $html = Blade::render(
            '<x-ui.progress :value="50" :max="100" variant="success" />'
        );

        self::assertStringContainsString('bg-brand-green', $html);
    }

    public function test_progress_variant_warning_uses_warning_color(): void
    {
        $html = Blade::render(
            '<x-ui.progress :value="50" :max="100" variant="warning" />'
        );

        self::assertStringContainsString('bg-warning', $html);
    }

    public function test_progress_variant_danger_uses_danger_color(): void
    {
        $html = Blade::render(
            '<x-ui.progress :value="50" :max="100" variant="danger" />'
        );

        self::assertStringContainsString('bg-danger', $html);
    }

    public function test_progress_renders_track_with_surface_background(): void
    {
        $html = Blade::render(
            '<x-ui.progress :value="50" :max="100" />'
        );

        // Track background uses surface token
        self::assertStringContainsString('bg-surface', $html);
        // Rounded pill shape for track and bar
        self::assertStringContainsString('rounded-button', $html);
    }
}
