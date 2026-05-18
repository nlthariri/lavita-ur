{{--
  Shared UI atom — `<x-ui.text-input>` (taak 8.5 spec lavita-urenregistratie).

  Bron:
  - design.md § Design tokens > Componenten:
      `text-input`        = `bg #fff; border 1px solid #e5e5e5; border-radius 8px;
                             height 40px; font-size 14px;`
      `text-input:focus`  = `border 2px solid #00d4a4; outline-offset 2px;`
  - requirements.md NFR-1 (focus-zichtbaar `border 2px #00d4a4`).
  - WCAG 2.1 AA — label-for-id, `aria-invalid`, `aria-describedby`.

  Anonymous component, wrapt label + input + optionele inline error en help.

  Props:
    - label       string   verplichte zichtbare tekst van het label.
    - name        string   form-name (default = ook id wanneer geen `id` gezet).
    - id          string   element-id; default = `name`.
    - type        string   input-type (text|email|password|number|date|time|tel|url|search).
    - value       mixed    voorgevulde waarde (echo via {{ }} = HTML-escaped).
    - placeholder string
    - required    bool
    - disabled    bool
    - autocomplete string
    - error       string|null inline foutmelding (rood + aria-invalid + aria-describedby).
    - help        string|null beschrijvende hulptekst (aria-describedby).

  Forwarding:
    - Alle overige attributen op het `<input>` worden via `$attributes` gemerged.
      `wire:model[.live|.lazy]` werkt out-of-the-box omdat Livewire de attribuut-bag
      respecteert.
--}}
@props([
    'label' => '',
    'name' => '',
    'id' => null,
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'required' => false,
    'disabled' => false,
    'autocomplete' => null,
    'error' => null,
    'help' => null,
])

@php
    $resolvedId = $id ?? ($name !== '' ? $name : 'input-'.uniqid());
    $errorId = $resolvedId.'-error';
    $helpId = $resolvedId.'-help';

    // Bouw de aria-describedby keten (help eerst, dan error) — alleen wanneer de
    // bijbehorende tekst aanwezig is.
    $describedBy = collect([
        $help ? $helpId : null,
        $error ? $errorId : null,
    ])->filter()->implode(' ');

    // Token-styling voor het input-element.
    // - `border-2 border-transparent` houdt het 40px-frame stabiel zodra
    //   `:focus` de border naar 2px brand-green opwaardeert (geen layout-shift).
    $inputClass = implode(' ', [
        'block w-full',
        'bg-canvas',
        'h-10 px-3',
        'rounded-input',
        'border-2 border-hairline',
        'text-body-sm text-ink placeholder:text-steel',
        'focus:outline-none focus:border-brand-green focus:ring-2 focus:ring-brand-green/20',
        'disabled:bg-surface disabled:text-steel disabled:cursor-not-allowed',
        $error ? 'border-danger focus:border-danger focus:ring-danger/20' : '',
    ]);
@endphp

<div {{ $attributes->only(['class'])->class(['flex flex-col gap-1']) }}>
    @if ($label !== '')
        <label for="{{ $resolvedId }}" class="text-body-sm font-medium text-ink">
            {{ $label }}
            @if ($required)
                <span class="text-danger" aria-hidden="true">*</span>
                <span class="sr-only">(verplicht)</span>
            @endif
        </label>
    @endif

    <input
        type="{{ $type }}"
        id="{{ $resolvedId }}"
        name="{{ $name }}"
        @if (! is_null($value)) value="{{ $value }}" @endif
        @if (! is_null($placeholder)) placeholder="{{ $placeholder }}" @endif
        @if (! is_null($autocomplete)) autocomplete="{{ $autocomplete }}" @endif
        @required($required)
        @disabled($disabled)
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        @if ($error) aria-invalid="true" @endif
        {{ $attributes->except(['class'])->class([$inputClass]) }}
    />

    @if ($help)
        <p id="{{ $helpId }}" class="text-body-sm text-steel">
            {{ $help }}
        </p>
    @endif

    @if ($error)
        <p
            id="{{ $errorId }}"
            class="error text-body-sm text-danger"
            role="alert"
            aria-live="polite"
        >
            {{ $error }}
        </p>
    @endif
</div>
