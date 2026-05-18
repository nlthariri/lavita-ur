{{--
  Shared UI atom — `<x-ui.status-badge>` (taak 8.5 spec lavita-urenregistratie).

  Bron:
  - design.md § Design tokens > Kleuren (statuskleuren):
      vastgesteld → bg #DCFCE7 / text #166534  (success)
      bezwaar     → bg #FEF9C3 / text #854D0E  (warning)
      concept     → bg #f7f7f7 / text #5a5a5c  (concept)
      (danger     → bg #FEE2E2 / text #991B1B  — uit tailwind.config.js, ATW-kritisch)
  - requirements.md NFR-4 — alleen tokens uit tailwind.config.js.

  Anonymous component dat een rond `<span>` rendert met kleine tekst en
  token-gebaseerde achter- en voorgrondkleuren.

  Props:
    - variant string  success | warning | danger | concept   (default: concept)
    - icon    bool    toont een gekleurde dot voor de tekst   (default: false)

  Voorbeeld:
      <x-ui.status-badge variant="success">Vastgesteld</x-ui.status-badge>
      <x-ui.status-badge variant="warning" icon>Bezwaar</x-ui.status-badge>
--}}
@props([
    'variant' => 'concept',
    'icon' => false,
])

@php
    /**
     * Kleur-mapping volgens design.md statustabel + tailwind.config.js
     * `success.bg/fg`, `warning.bg/fg`, `danger.bg/fg`, `concept.bg/fg`.
     */
    $variantClasses = [
        'success' => 'bg-success-bg text-success-fg',
        'warning' => 'bg-warning-bg text-warning-fg',
        'danger' => 'bg-danger-bg text-danger-fg',
        'concept' => 'bg-concept-bg text-concept-fg',
    ];

    $variantClass = $variantClasses[$variant] ?? $variantClasses['concept'];

    /**
     * Dot-kleuren matchen de voorgrondkleur van de variant zodat het visueel
     * geclusterd blijft en contrastregels niet breken.
     */
    $dotClasses = [
        'success' => 'bg-success-fg',
        'warning' => 'bg-warning-fg',
        'danger' => 'bg-danger-fg',
        'concept' => 'bg-concept-fg',
    ];

    $dotClass = $dotClasses[$variant] ?? $dotClasses['concept'];

    $baseClass = implode(' ', [
        'inline-flex items-center gap-1.5',
        'rounded-full',
        'px-2.5 py-0.5',
        'text-body-sm font-medium',
        'whitespace-nowrap',
    ]);
@endphp

<span
    {{ $attributes->class([$baseClass, $variantClass]) }}
    data-variant="{{ $variant }}"
>
    @if ($icon)
        <span class="inline-block h-2 w-2 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
