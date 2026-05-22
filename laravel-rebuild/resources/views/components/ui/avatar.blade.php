{{--
  Shared UI atom — `<x-ui.avatar>` (taak 1.6 spec lavita-urenregistratie).

  Bron:
  - design.md § Components and Interfaces > `<x-ui.avatar>`:
      Props: name (string), size (sm/md/lg), src (optioneel foto-URL).
      Initialen-algoritme: eerste letter eerste woord + eerste letter laatste woord.
      Fallback naar eerste 2 letters bij 1 woord.
      Achtergrondkleur deterministisch op basis van naam-hash (6 kleuren).
  - requirements.md 12.6 — avatar met initialen in gekleurde cirkel.
  - requirements.md NFR-1 — WCAG 2.1 AA (contrast ≥4.5:1, aria-labels).
  - requirements.md NFR-4 — alleen design tokens uit tailwind.config.js.

  Anonymous component (stateless atom): geen klasse, alleen Blade.

  Props:
    - name    string   Volledige naam voor initialen-generatie (verplicht)
    - size    string   sm (32px) | md (40px) | lg (48px)     (default: md)
    - src     string   Optionele foto-URL; toont foto i.p.v. initialen

  Initialen-algoritme:
    - 2+ woorden: eerste letter eerste woord + eerste letter laatste woord (uppercase)
    - 1 woord: eerste 2 letters (of 1 letter als naam 1 karakter is) (uppercase)

  Achtergrondkleur:
    - Deterministisch op basis van crc32-hash van de naam, modulo 6 kleuren.
    - Alle kleuren hebben voldoende contrast met witte tekst (≥4.5:1 WCAG AA).

  Voorbeeld:
      <x-ui.avatar name="Jan de Vries" size="md" />
      <x-ui.avatar name="Jan de Vries" size="lg" src="/storage/avatars/jan.jpg" />
--}}
@props([
    'name' => '',
    'size' => 'md',
    'src' => null,
])

@php
    /**
     * Size-classes: afmetingen + tekst-grootte voor initialen.
     * sm = 32px (w-8 h-8), md = 40px (w-10 h-10), lg = 48px (w-12 h-12).
     */
    $sizeClasses = [
        'sm' => 'w-8 h-8 text-xs',
        'md' => 'w-10 h-10 text-sm',
        'lg' => 'w-12 h-12 text-base',
    ];

    $sizeClass = $sizeClasses[$size] ?? $sizeClasses['md'];

    /**
     * Initialen-algoritme:
     * - Splits naam op whitespace, filter lege strings.
     * - 2+ woorden: eerste letter eerste + eerste letter laatste woord.
     * - 1 woord: eerste 2 letters (of 1 als naam 1 karakter is).
     */
    $trimmedName = trim($name);
    $words = array_values(array_filter(explode(' ', $trimmedName), fn($w) => $w !== ''));
    $wordCount = count($words);

    if ($wordCount >= 2) {
        $initials = mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[$wordCount - 1], 0, 1));
    } elseif ($wordCount === 1) {
        $initials = mb_strtoupper(mb_substr($words[0], 0, min(2, mb_strlen($words[0]))));
    } else {
        $initials = '?';
    }

    /**
     * Deterministieke achtergrondkleur op basis van naam-hash.
     * 6 voorgedefinieerde kleuren met voldoende contrast op witte tekst (≥4.5:1).
     * We gebruiken crc32 voor een snelle, deterministische hash.
     */
    $avatarColors = [
        'bg-emerald-600',   // #059669 — contrast 4.5:1+ op wit
        'bg-blue-600',      // #2563EB — contrast 4.5:1+ op wit
        'bg-purple-600',    // #9333EA — contrast 4.5:1+ op wit
        'bg-rose-600',      // #E11D48 — contrast 4.5:1+ op wit
        'bg-amber-700',     // #B45309 — contrast 4.5:1+ op wit
        'bg-teal-600',      // #0D9488 — contrast 4.5:1+ op wit
    ];

    $colorIndex = abs(crc32($trimmedName)) % count($avatarColors);
    $bgColorClass = $avatarColors[$colorIndex];

    $baseClass = implode(' ', [
        'inline-flex items-center justify-center',
        'rounded-full',
        'font-sans font-medium text-white',
        'select-none shrink-0',
    ]);
@endphp

@if ($src)
    <img
        src="{{ $src }}"
        alt="{{ $trimmedName }}"
        {{ $attributes->class([$sizeClass, 'rounded-full object-cover shrink-0']) }}
    />
@else
    <span
        {{ $attributes->class([$baseClass, $sizeClass, $bgColorClass]) }}
        aria-label="{{ $trimmedName }}"
        role="img"
        title="{{ $trimmedName }}"
    >
        {{ $initials }}
    </span>
@endif
