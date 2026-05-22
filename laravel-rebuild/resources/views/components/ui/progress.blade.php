{{--
  Shared UI atom — `<x-ui.progress>` (taak 1.3 spec lavita-urenregistratie).

  Bron:
  - design.md § Components and Interfaces > `<x-ui.progress>`
  - requirements.md 12.3, 12.8, 12.10
  - Property 10: Progress-bar breedte is proportioneel aan value/max

  Props:
    - value           int|float   Huidige waarde (0-100 of absolute waarde)   (default: 0)
    - max             int|float   Maximum waarde                               (default: 100)
    - variant         string      success | warning | danger                   (default: success)
    - label           string      Beschrijvend label boven de balk             (default: '')
    - showPercentage  bool        Toon percentage-tekst rechts van de balk     (default: true)

  Berekening:
    width% = min(100, max(0, (value / max) * 100))

  Variant-kleuren (design tokens uit tailwind.config.js):
    - success → bg-brand-green
    - warning → bg-warning
    - danger  → bg-danger

  Toegankelijkheid (WCAG 2.1 AA — NFR-1):
    - `role="progressbar"` op de buitenste container
    - `aria-valuenow` = value
    - `aria-valuemin` = 0
    - `aria-valuemax` = max
    - `aria-label` = label (wanneer opgegeven)

  Voorbeeld:
    <x-ui.progress :value="32" :max="40" variant="success" label="Uren deze week" />
    <x-ui.progress :value="18" :max="25" variant="warning" label="Verlofdagen" :showPercentage="false" />
    <x-ui.progress :value="25" :max="25" variant="danger" label="Saldo op" />
--}}
@props([
    'value' => 0,
    'max' => 100,
    'variant' => 'success',
    'label' => '',
    'showPercentage' => true,
])

@php
    // Voorkom deling door nul; behandel max <= 0 als 100%.
    $safeMax = $max > 0 ? $max : 1;

    // Breedte-berekening: min(100, max(0, (value / max) * 100))
    $percentage = min(100, max(0, ($value / $safeMax) * 100));

    // Variant → achtergrondkleur van de voortgangsbalk.
    $variantClasses = [
        'success' => 'bg-brand-green',
        'warning' => 'bg-warning',
        'danger'  => 'bg-danger',
    ];

    $barClass = $variantClasses[$variant] ?? $variantClasses['success'];
@endphp

<div {{ $attributes->class(['w-full']) }}>
    {{-- Label + percentage --}}
    @if ($label || $showPercentage)
        <div class="mb-1 flex items-center justify-between text-body-sm text-ink">
            @if ($label)
                <span id="{{ $attributes->get('id', uniqid('progress-')) }}-label">{{ $label }}</span>
            @else
                <span></span>
            @endif

            @if ($showPercentage)
                <span class="text-steel">{{ round($percentage) }}%</span>
            @endif
        </div>
    @endif

    {{-- Progress bar track --}}
    <div
        role="progressbar"
        aria-valuenow="{{ $value }}"
        aria-valuemin="0"
        aria-valuemax="{{ $max }}"
        @if ($label) aria-label="{{ $label }}" @endif
        class="h-2.5 w-full overflow-hidden rounded-button bg-surface"
    >
        {{-- Progress bar fill --}}
        <div
            class="{{ $barClass }} h-full rounded-button transition-all duration-300 ease-in-out"
            style="width: {{ $percentage }}%"
        ></div>
    </div>
</div>
