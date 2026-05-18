{{--
  Shared UI atom — `<x-ui.card>` (taak 8.5 spec lavita-urenregistratie).

  Bron:
  - design.md § Design tokens > Componenten:
      `card-base` = `bg #fff; border 1px solid #e5e5e5; border-radius 12px; padding 24px;`
  - requirements.md NFR-4 — alleen design tokens uit tailwind.config.js.

  Anonymous component met benoemde slots `header`, `footer` en de default-slot
  voor de body. Tasks.md 8.5 vraagt expliciet om die drie slots.

  Voorbeeld:
      <x-ui.card>
          <x-slot:header>
              <h2 class="text-heading-2">Mijn week</h2>
          </x-slot:header>

          Body-inhoud hier…

          <x-slot:footer>
              <x-ui.button variant="secondary">Annuleren</x-ui.button>
          </x-slot:footer>
      </x-ui.card>
--}}
@props([
    'as' => 'section',
])

@php
    // `bg-canvas` (#FFFFFF) komt overeen met `card-base` token. `bg-white` zou ook werken,
    // maar we forceren consistente token-namen volgens NFR-4.
    $cardClass = implode(' ', [
        'bg-canvas',
        'border border-hairline',
        'rounded-card',
        'p-6',
        'text-ink',
    ]);
@endphp

<{{ $as }} {{ $attributes->class([$cardClass]) }}>
    @isset($header)
        <header class="mb-4 border-b border-hairline pb-4">
            {{ $header }}
        </header>
    @endisset

    <div class="text-body-md">
        {{ $slot }}
    </div>

    @isset($footer)
        <footer class="mt-4 border-t border-hairline pt-4">
            {{ $footer }}
        </footer>
    @endisset
</{{ $as }}>
