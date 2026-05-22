{{--
  Shared UI atom — `<x-ui.stat-card>` (taak 1.5 spec lavita-urenregistratie).

  Bron:
  - design.md § Components and Interfaces > `<x-ui.stat-card>`
  - requirements.md 12.5 — stat-card met trend-indicator
  - requirements.md 1.9 — KPI_Card als `<x-ui.card>` met gekleurde accent-rand

  Wraps `<x-ui.card>` met een gekleurde `border-l-4` accent-rand:
    - trend="up"      → border-brand-green (#00d4a4) + groene pijl omhoog
    - trend="down"    → border-danger (#ef4444) + rode pijl omlaag
    - trend="neutral" → border-hairline (#e5e5e5) + neutrale streep

  Props:
    - title       string          KPI-label (bijv. "Totaal uren deze week")
    - value       string|number   Hoofdwaarde (bijv. "42:30" of 12)
    - trend       string          up | down | neutral   (default: neutral)
    - trendValue  string          Trend-tekst (bijv. "+12%" of "-3 uur")
    - icon        string|null     Optioneel SVG-icoon (raw HTML of Blade-slot)

  Voorbeeld:
      <x-ui.stat-card
          title="Totaal uren"
          value="42:30"
          trend="up"
          trend-value="+12%"
      />

  Toegankelijkheid:
    - Trend-pijl heeft `aria-hidden="true"` (decoratief).
    - Trend-waarde wordt voorgelezen via sr-only tekst met context.
--}}
@props([
    'title' => '',
    'value' => '',
    'trend' => 'neutral',
    'trendValue' => '',
    'icon' => null,
])

@php
    /**
     * Border-accent per trend-richting.
     * Mappen op design tokens uit tailwind.config.js.
     */
    $borderClasses = [
        'up' => 'border-l-4 border-l-brand-green',
        'down' => 'border-l-4 border-l-danger',
        'neutral' => 'border-l-4 border-l-hairline',
    ];

    $borderClass = $borderClasses[$trend] ?? $borderClasses['neutral'];

    /**
     * Trend-tekst kleur per richting.
     */
    $trendTextClasses = [
        'up' => 'text-brand-green',
        'down' => 'text-danger',
        'neutral' => 'text-steel',
    ];

    $trendTextClass = $trendTextClasses[$trend] ?? $trendTextClasses['neutral'];

    /**
     * Screen-reader context voor de trend.
     */
    $trendLabels = [
        'up' => 'Stijging',
        'down' => 'Daling',
        'neutral' => 'Ongewijzigd',
    ];

    $trendLabel = $trendLabels[$trend] ?? $trendLabels['neutral'];
@endphp

<x-ui.card {{ $attributes->class([$borderClass]) }}>
    <div class="flex items-start justify-between gap-3">
        {{-- Linker content: titel + waarde + trend --}}
        <div class="min-w-0 flex-1">
            <p class="text-body-sm text-steel truncate">{{ $title }}</p>

            <p class="mt-1 text-2xl font-semibold text-ink">{{ $value }}</p>

            @if ($trendValue)
                <div class="mt-2 flex items-center gap-1.5">
                    {{-- Trend-pijl icoon --}}
                    @if ($trend === 'up')
                        <svg class="h-4 w-4 {{ $trendTextClass }}" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 17a.75.75 0 0 1-.75-.75V5.612L5.29 9.77a.75.75 0 0 1-1.08-1.04l5.25-5.5a.75.75 0 0 1 1.08 0l5.25 5.5a.75.75 0 1 1-1.08 1.04l-3.96-4.158V16.25A.75.75 0 0 1 10 17Z" clip-rule="evenodd" />
                        </svg>
                    @elseif ($trend === 'down')
                        <svg class="h-4 w-4 {{ $trendTextClass }}" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 3a.75.75 0 0 1 .75.75v10.638l3.96-4.158a.75.75 0 1 1 1.08 1.04l-5.25 5.5a.75.75 0 0 1-1.08 0l-5.25-5.5a.75.75 0 1 1 1.08-1.04l3.96 4.158V3.75A.75.75 0 0 1 10 3Z" clip-rule="evenodd" />
                        </svg>
                    @else
                        <svg class="h-4 w-4 {{ $trendTextClass }}" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M4 10a.75.75 0 0 1 .75-.75h10.5a.75.75 0 0 1 0 1.5H4.75A.75.75 0 0 1 4 10Z" clip-rule="evenodd" />
                        </svg>
                    @endif

                    {{-- Trend-waarde --}}
                    <span class="text-body-sm font-medium {{ $trendTextClass }}">
                        {{ $trendValue }}
                    </span>

                    {{-- Screen-reader context --}}
                    <span class="sr-only">{{ $trendLabel }}: {{ $trendValue }}</span>
                </div>
            @endif
        </div>

        {{-- Rechter icoon (optioneel) --}}
        @if ($icon || isset($icon) && $icon instanceof \Illuminate\View\ComponentSlot)
            <div class="flex-shrink-0 text-steel" aria-hidden="true">
                @if ($icon instanceof \Illuminate\View\ComponentSlot)
                    {{ $icon }}
                @else
                    {!! $icon !!}
                @endif
            </div>
        @endif
    </div>
</x-ui.card>
