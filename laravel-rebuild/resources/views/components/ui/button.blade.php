{{--
  Shared UI atom — `<x-ui.button>` (taak 8.5 spec lavita-urenregistratie).

  Bron:
  - design.md § Components and Interfaces > Design tokens
      `button-primary`   → bg #0a0a0a / on-primary #FFF / radius 9999px
      `button-secondary` → border 1px #e5e5e5 / radius 9999px
  - requirements.md NFR-1 (focus-zichtbaar `border 2px #00d4a4`)
  - requirements.md NFR-4 (alleen design tokens uit tailwind.config.js)

  Anonymous component (stateless atom): geen klasse, alleen Blade.

  Props:
    - variant   string   primary | secondary | ghost | danger   (default: primary)
    - type      string   submit | button | reset                (default: button)
    - as        string   button | a                              (default: button)
    - href      string   verplicht wanneer as=a
    - disabled  bool     ARIA + visueel disabled                 (default: false)
    - loading   bool     toont spinner + zet aria-busy           (default: false)

  Forwarding:
    - Alle overige attributen (`wire:click`, `id`, `name`, `data-*`, `aria-*`)
      via `$attributes` mergen — Tailwind-classes kunnen door consumers
      worden uitgebreid via `class="..."`.

  Toegankelijkheid:
    - Keyboard-fokus is globaal afgehandeld in layouts/app.blade.php
      (`:focus-visible { outline: 2px solid #00d4a4; outline-offset: 2px; }`).
      Daarnaast voegt deze component `focus:ring-2 focus:ring-brand-green
      focus:ring-offset-2` toe voor componenten die zelfstandig gerenderd
      worden in tests of preview-pagina's.
    - `disabled` zet `aria-disabled` plus `pointer-events-none opacity-60`.
    - `loading` zet `aria-busy="true"` en blokkeert de click-actie via
      `disabled`/`aria-disabled` zodat dubbele submits voorkomen worden.
--}}
@props([
    'variant' => 'primary',
    'type' => 'button',
    'as' => 'button',
    'href' => null,
    'disabled' => false,
    'loading' => false,
])

@php
    /**
     * Variant-classes — mappen 1-op-1 op `button-primary` / `button-secondary`
     * tokens uit design.md. `ghost` en `danger` voegen we toe voor de varianten
     * die in tasks.md 8.5 expliciet genoemd worden.
     */
    $variantClasses = [
        'primary' => 'bg-primary text-on-primary border border-transparent hover:bg-ink/90',
        'secondary' => 'bg-transparent text-ink border border-hairline hover:bg-surface',
        'ghost' => 'bg-transparent text-ink border border-transparent hover:bg-surface',
        'danger' => 'bg-danger text-on-primary border border-transparent hover:bg-danger/90',
    ];

    $variantClass = $variantClasses[$variant] ?? $variantClasses['primary'];

    $baseClass = implode(' ', [
        // Layout + token-radius (button = 9999px) + token-typografie button-md.
        'inline-flex items-center justify-center gap-2',
        'rounded-button px-5 py-2.5',
        'font-sans text-button-md',
        // Focus-ring uit ringColor.DEFAULT (#00d4a4) — NFR-1 vereiste.
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2',
        'transition-colors',
        // Disabled-styling.
        'disabled:opacity-60 disabled:pointer-events-none',
    ]);

    $isDisabled = (bool) $disabled || (bool) $loading;
@endphp

@if ($as === 'a')
    <a
        @if (! $isDisabled) href="{{ $href }}" @endif
        @if ($isDisabled) aria-disabled="true" tabindex="-1" @endif
        @if ($loading) aria-busy="true" @endif
        role="button"
        {{ $attributes->class([$baseClass, $variantClass, 'pointer-events-none opacity-60' => $isDisabled])->except(['href']) }}
    >
        @if ($loading)
            <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true"></span>
        @endif
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        @disabled($isDisabled)
        @if ($isDisabled) aria-disabled="true" @endif
        @if ($loading) aria-busy="true" @endif
        {{ $attributes->class([$baseClass, $variantClass]) }}
    >
        @if ($loading)
            <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true"></span>
        @endif
        {{ $slot }}
    </button>
@endif
