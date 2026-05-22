<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Smoke-test voor task 8.5 — shared UI Blade-componenten.
 *
 * Doel: bevestigen dat de vier `<x-ui.*>` anonymous components renderen
 * en dat de design-tokens (kleur-, radius- en focus-classes uit
 * `tailwind.config.js`) in de output staan, conform:
 *   - requirements.md 6.13, NFR-1, NFR-4
 *   - design.md § "Design tokens" — `button-primary`, `card-base`,
 *     `text-input`, status-badge-kleuren, focus-state #00d4a4.
 *
 * We gebruiken `Blade::render()` om de component los van de app-layout
 * te compileren — geen DB nodig, dus geen RefreshDatabase-trait. Per
 * component één test om de testfile bewust simpel te houden.
 */
final class UiComponentsRenderTest extends TestCase
{
    public function test_button_renders_with_primary_token_classes_and_focus_ring(): void
    {
        $html = Blade::render(
            '<x-ui.button>Opslaan</x-ui.button>'
        );

        // Slot-content rendert.
        self::assertStringContainsString('Opslaan', $html);

        // Default = button-element met type="button".
        self::assertStringContainsString('<button', $html);
        self::assertStringContainsString('type="button"', $html);

        // Pill-radius (token `button` = 9999px).
        self::assertStringContainsString('rounded-button', $html);

        // Primary-variant token-classes.
        self::assertStringContainsString('bg-primary', $html);
        self::assertStringContainsString('text-on-primary', $html);

        // Focus-state — NFR-1 vereist `border 2px #00d4a4`; we zetten het
        // visueel via een 2px ring in brand-green.
        self::assertStringContainsString('focus-visible:ring-2', $html);
        self::assertStringContainsString('focus-visible:ring-brand-green', $html);
    }

    public function test_card_renders_with_card_token_classes_and_named_slots(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.card>
                <x-slot:header>Kop</x-slot:header>
                Body-tekst
                <x-slot:footer>Voet</x-slot:footer>
            </x-ui.card>
        BLADE);

        // Slots en default slot komen alle drie in de output terecht.
        self::assertStringContainsString('Kop', $html);
        self::assertStringContainsString('Body-tekst', $html);
        self::assertStringContainsString('Voet', $html);

        // `card-base` token-classes (bg #fff, border #e5e5e5, radius 12, padding 24).
        self::assertStringContainsString('bg-canvas', $html);
        self::assertStringContainsString('border-hairline', $html);
        self::assertStringContainsString('rounded-card', $html);
        self::assertStringContainsString('p-6', $html);

        // Default tag = <section>.
        self::assertStringContainsString('<section', $html);
    }

    public function test_text_input_renders_label_input_error_with_aria_links(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.text-input
                name="email"
                label="E-mail"
                type="email"
                error="Ongeldig e-mailadres"
                help="We sturen je een bevestiging"
                required
            />
        BLADE);

        // Label gekoppeld aan input via for/id.
        self::assertStringContainsString('for="email"', $html);
        self::assertStringContainsString('id="email"', $html);
        self::assertStringContainsString('E-mail', $html);

        // Type komt door.
        self::assertStringContainsString('type="email"', $html);

        // Token-styling voor het input-frame.
        self::assertStringContainsString('rounded-input', $html);
        self::assertStringContainsString('border-hairline', $html);

        // Focus-state — NFR-1 `border 2px #00d4a4`.
        self::assertStringContainsString('focus:border-brand-green', $html);

        // Inline error met `.error` class + aria-invalid + aria-describedby koppeling.
        self::assertStringContainsString('class="error', $html);
        self::assertStringContainsString('Ongeldig e-mailadres', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('aria-describedby="email-help email-error"', $html);

        // Help-tekst.
        self::assertStringContainsString('We sturen je een bevestiging', $html);

        // Required: HTML-attribuut + screenreader-only "(verplicht)".
        self::assertStringContainsString('required', $html);
        self::assertStringContainsString('(verplicht)', $html);
    }

    public function test_toast_renders_alpine_container_with_aria_attributes(): void
    {
        $html = Blade::render('<x-ui.toast />');

        // Alpine.js toastManager data-binding.
        self::assertStringContainsString('x-data="toastManager()"', $html);

        // Luistert naar @toast.window events (Livewire dispatch).
        self::assertStringContainsString('x-on:toast.window="addToast($event.detail)"', $html);

        // ARIA: role="alert" en aria-live="polite" op individuele toasts.
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);

        // Sluit-knop met aria-label="Melding sluiten".
        self::assertStringContainsString('aria-label="Melding sluiten"', $html);

        // Positioning: fixed top-4 (responsive).
        self::assertStringContainsString('fixed', $html);
        self::assertStringContainsString('top-4', $html);

        // Desktop: right-4, mobiel: inset-x-4.
        self::assertStringContainsString('inset-x-4', $html);
        self::assertStringContainsString('tablet:right-4', $html);

        // Slide-in animatie classes (enter-start).
        self::assertStringContainsString('translate-x-full', $html);

        // Hover-pause functionaliteit (mouseenter/mouseleave handlers).
        self::assertStringContainsString('pauseToast(toast.id)', $html);
        self::assertStringContainsString('resumeToast(toast.id)', $html);

        // Variant-styling via Alpine x-bind:class.
        self::assertStringContainsString('variantClasses(toast.variant)', $html);

        // Variant-icoon via Alpine x-html.
        self::assertStringContainsString('variantIcon(toast.variant)', $html);
    }

    public function test_toast_alpine_script_contains_queue_and_timing_logic(): void
    {
        // De @push('scripts') content wordt niet gerenderd door Blade::render()
        // maar wel wanneer de component in een layout met @stack('scripts') staat.
        // We testen het script-bestand direct.
        $componentSource = file_get_contents(
            resource_path('views/components/ui/toast.blade.php')
        );

        // Queue-logica: max 3 zichtbaar.
        self::assertStringContainsString('maxVisible: 3', $componentSource);

        // Auto-dismiss durations.
        self::assertStringContainsString('8000', $componentSource); // error duration
        self::assertStringContainsString('5000', $componentSource); // default duration

        // Hover-pause timer logica.
        self::assertStringContainsString('pauseToast', $componentSource);
        self::assertStringContainsString('resumeToast', $componentSource);

        // Alpine.js registratie.
        self::assertStringContainsString("Alpine.data('toastManager'", $componentSource);

        // Variant-kleuren mapping.
        self::assertStringContainsString('bg-success-bg', $componentSource);
        self::assertStringContainsString('bg-danger-bg', $componentSource);
        self::assertStringContainsString('bg-warning-bg', $componentSource);
    }

    public function test_status_badge_renders_rounded_pill_with_variant_tokens(): void
    {
        $html = Blade::render(
            '<x-ui.status-badge variant="success">Vastgesteld</x-ui.status-badge>'
        );

        // Slot-content rendert.
        self::assertStringContainsString('Vastgesteld', $html);

        // Span met pill-radius.
        self::assertStringContainsString('<span', $html);
        self::assertStringContainsString('rounded-full', $html);

        // Success-variant token-kleuren (bg #DCFCE7 / text #166534).
        self::assertStringContainsString('bg-success-bg', $html);
        self::assertStringContainsString('text-success-fg', $html);

        // Variant gepubliceerd als data-attribute voor styling/E2E-tests.
        self::assertStringContainsString('data-variant="success"', $html);
    }
}
