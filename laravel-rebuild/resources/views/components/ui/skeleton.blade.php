{{--
  Shared UI atom — `<x-ui.skeleton>` (taak 1.4 spec lavita-urenregistratie).

  Bron:
  - design.md § Components and Interfaces > `<x-ui.skeleton>`:
      Pulserende placeholder (`animate-pulse bg-surface rounded`).
      Types: text (variërende breedte), card (120px), chart (200px), avatar (cirkel).
  - requirements.md 12.4 — skeleton component met type en lines props.
  - requirements.md NFR-4 — alleen design tokens uit tailwind.config.js.

  Props:
    - type   string   text | card | chart | avatar   (default: text)
    - lines  int      Aantal regels voor type=text    (default: 3)

  Voorbeelden:
      {{-- Tekst-skeleton met 4 regels --}}
      <x-ui.skeleton type="text" :lines="4" />

      {{-- Card-skeleton (120px hoog) --}}
      <x-ui.skeleton type="card" />

      {{-- Chart-skeleton (200px hoog) --}}
      <x-ui.skeleton type="chart" />

      {{-- Avatar-skeleton (cirkel) --}}
      <x-ui.skeleton type="avatar" />

  Toegankelijkheid:
    - `aria-hidden="true"` omdat skeletons puur decoratief zijn.
    - `role="status"` met `aria-label` zodat screenreaders weten dat
      content aan het laden is.
--}}
@props([
    'type' => 'text',
    'lines' => 3,
])

@php
    $baseClass = 'animate-pulse';

    // Variërende breedtes voor tekst-regels (cyclisch patroon).
    $textWidths = ['w-full', 'w-4/5', 'w-3/5'];
@endphp

<div
    {{ $attributes->class([$baseClass]) }}
    role="status"
    aria-label="Inhoud wordt geladen"
    aria-hidden="true"
>
    @switch($type)
        @case('text')
            <div class="space-y-3">
                @for ($i = 0; $i < (int) $lines; $i++)
                    <div class="{{ $textWidths[$i % count($textWidths)] }} h-4 rounded bg-surface"></div>
                @endfor
            </div>
            @break

        @case('card')
            <div class="h-[120px] w-full rounded-card bg-surface"></div>
            @break

        @case('chart')
            <div class="h-[200px] w-full rounded bg-surface"></div>
            @break

        @case('avatar')
            <div class="h-10 w-10 rounded-full bg-surface"></div>
            @break

        @default
            <div class="space-y-3">
                @for ($i = 0; $i < (int) $lines; $i++)
                    <div class="{{ $textWidths[$i % count($textWidths)] }} h-4 rounded bg-surface"></div>
                @endfor
            </div>
    @endswitch
</div>
