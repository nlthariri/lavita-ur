{{--
  Hoofd-applayout — LaVita Urenregistratie.

  Bron: `.kiro/specs/lavita-urenregistratie/design.md` (Components and Interfaces > Grid)
  en `requirements.md` 6.13 + NFR-1 / NFR-3 / NFR-4 / NFR-10.

  Verantwoordelijkheden:
  - Document-skelet (`<html lang="nl">`, charset, viewport, CSRF, title).
  - Volledig WCAG 2.1 AA-conform skip-to-main-link als eerste focuseerbaar element.
  - Semantische landmarks: `<header>`, `<nav>`, `<main>`, `<footer>` (+ optionele
    `<aside>` voor de inhoudsopgave op desktop).
  - 3-koloms grid sidebar 240px / content max 720px / TOC 200px op desktop ≥1280,
    2-koloms (sidebar 240 / fluid content) op tablet 768-1279, 1-koloms met
    hamburger op mobiel <768.
  - Vite-asset injectie voor Tailwind (`resources/css/app.css`) en JS-bundle.
  - Livewire-3 styles/scripts via `@livewireStyles` / `@livewireScripts`.
  - Globale focus-state `border 2px solid #00d4a4` (focus-zichtbaar — NFR-1).

  Slot-mechaniek:
  - Child-views gebruiken `@extends('layouts.app')` + `@section('content') ... @endsection`.
  - Optionele extra head-content via `@push('head') ... @endpush`.
  - Voor Livewire-component-pagina's bestaat een aparte layout op
    `components/layouts/app.blade.php` (taak 8.5); deze layout is bedoeld voor
    klassieke Blade-pagina's met `@yield`.
--}}
<!DOCTYPE html>
<html lang="nl" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'LaVita Urenregistratie')</title>

    {{-- Geist Mono — Inter wordt via @fontsource/inter in resources/css/app.css ingeladen. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Geist+Mono:wght@400;500;600&display=swap"
    >

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{-- Globale focus-zichtbaarheid (NFR-1: border 2px #00d4a4). --}}
    <style>
        :where(a, button, input, select, textarea, summary, [tabindex]):focus-visible {
            outline: 2px solid #00d4a4;
            outline-offset: 2px;
            border-radius: 4px;
        }
        /* Bij muis-klik geen ring tonen (focus-visible houdt het tot toetsenbord). */
        :where(a, button, input, select, textarea, summary, [tabindex]):focus:not(:focus-visible) {
            outline: none;
        }
    </style>

    @stack('head')
</head>
<body class="h-full min-h-screen bg-canvas font-sans text-body-md text-ink antialiased">
    {{-- Skip-link: eerste focuseerbaar element op de pagina (WCAG 2.4.1). --}}
    <a
        href="#main"
        class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-primary focus:px-4 focus:py-2 focus:text-on-primary focus:no-underline"
    >
        Sla over naar hoofdinhoud
    </a>

    <div class="mx-auto flex min-h-screen max-w-content flex-col px-4 tablet:px-gutter">
        {{-- Sitehoofd: titel + plek voor gebruikersmenu. --}}
        <header
            role="banner"
            class="flex items-center justify-between gap-4 border-b border-hairline py-4"
        >
            <div class="flex items-center gap-3">
                {{-- Mobiele hamburger — alleen tonen als ingelogd --}}
                @auth
                <button
                    type="button"
                    x-data
                    x-on:click="$dispatch('toggle-sidebar')"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-input border border-hairline text-ink tablet:hidden"
                    aria-label="Navigatie openen of sluiten"
                    aria-controls="primary-navigation"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="h-5 w-5"
                        aria-hidden="true"
                        focusable="false"
                    >
                        <line x1="4" y1="6" x2="20" y2="6"></line>
                        <line x1="4" y1="12" x2="20" y2="12"></line>
                        <line x1="4" y1="18" x2="20" y2="18"></line>
                    </svg>
                </button>
                @endauth

                <a href="{{ url('/dashboard') }}" class="text-button-md font-semibold text-ink no-underline">
                    LaVita&nbsp;Urenregistratie
                </a>
            </div>

            <div class="flex items-center gap-3" aria-label="Gebruikersmenu">
                @auth
                    <a href="/profiel" class="hidden text-body-sm text-steel no-underline hover:text-ink tablet:inline">
                        {{ auth()->user()->full_name ?? auth()->user()->name ?? '' }}
                    </a>
                    <form method="POST" action="{{ url('/uitloggen') }}" class="inline">
                        @csrf
                        <button
                            type="submit"
                            class="rounded-button border border-hairline bg-transparent px-4 py-2 text-button-md text-ink"
                        >
                            Uitloggen
                        </button>
                    </form>
                @else
                    <a
                        href="{{ url('/inloggen') }}"
                        class="rounded-button bg-primary px-4 py-2 text-button-md text-on-primary no-underline"
                    >
                        Inloggen
                    </a>
                @endauth
            </div>
        </header>

        {{--
          App-grid:
            mobiel  <768  : 1-koloms (sidebar verschuift naar collapsible drawer)
            tablet  768+  : 2-koloms (sidebar 240 / fluid content)
            desktop 1280+ : 3-koloms (sidebar 240 / content max 720 / TOC 200)
        --}}
        <div
            @if (auth()->check())
                class="grid flex-1 grid-cols-1 gap-6 py-6 tablet:grid-cols-[theme(spacing.sidebar)_minmax(0,1fr)] tablet:gap-gutter desktop:grid-cols-[theme(spacing.sidebar)_minmax(0,720px)_theme(spacing.toc)]"
            @else
                class="flex flex-1 flex-col gap-6 py-6"
            @endif
        >
            {{-- Hoofdnavigatie. Alleen tonen als ingelogd. --}}
            @auth
            <nav
                id="primary-navigation"
                aria-label="Hoofdnavigatie"
                x-data="{ open: false }"
                x-on:toggle-sidebar.window="open = !open"
                x-on:keydown.escape.window="open = false"
                x-bind:class="open ? 'block' : 'hidden'"
                class="tablet:!block"
            >
                <ul class="flex flex-col gap-1 text-body-sm">
                    @php
                        $userRole = (string) (auth()->user()->role ?? '');

                        // Navigatie-items met rol-restrictie
                        // 'roles' => null betekent: zichtbaar voor iedereen
                        $navItems = [
                            ['label' => 'Dashboard',    'href' => '/dashboard',       'roles' => null],
                            ['label' => 'Uren',         'href' => '/uren/week',       'roles' => ['owner', 'manager']],
                            ['label' => 'Mijn week',    'href' => '/uren/mijn-week',  'roles' => null],
                            ['label' => 'Verlof',       'href' => '/verlof',          'roles' => null],
                            ['label' => 'Verlofoverzicht', 'href' => '/verlof/overzicht', 'roles' => ['owner', 'manager']],
                            ['label' => 'Verlofkalender', 'href' => '/verlof/kalender', 'roles' => ['owner', 'manager', 'boekhouder']],
                            ['label' => 'Bezwaren',     'href' => '/bezwaren',        'roles' => null],
                            ['label' => 'ATW',          'href' => '/atw',             'roles' => ['owner', 'manager', 'boekhouder']],
                            ['label' => 'Rapportages',  'href' => '/rapportages',     'roles' => ['owner', 'manager', 'boekhouder']],
                            ['label' => 'Accounts',     'href' => '/accounts',        'roles' => ['owner', 'manager']],
                            ['label' => 'Instellingen', 'href' => '/instellingen',    'roles' => ['owner', 'manager']],
                            ['label' => 'Profiel',      'href' => '/profiel',         'roles' => null],
                        ];
                        $current = '/'.ltrim(request()->path(), '/');
                    @endphp
                    @foreach ($navItems as $item)
                        @php
                            // Skip items die niet voor deze rol zijn
                            if ($item['roles'] !== null && !in_array($userRole, $item['roles'], true)) {
                                continue;
                            }
                            $isActive = $current === $item['href']
                                || str_starts_with($current, $item['href'].'/');
                        @endphp
                        <li>
                            <a
                                href="{{ $item['href'] }}"
                                @if ($isActive) aria-current="page" @endif
                                class="block rounded-input px-3 py-2 text-ink no-underline hover:bg-surface aria-[current=page]:bg-surface aria-[current=page]:font-medium"
                            >
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
            @endauth

            {{-- Hoofdinhoud — id matcht skip-link. tabindex=-1 zodat focus erop kan landen. --}}
            <main
                id="main"
                tabindex="-1"
                class="min-w-0 max-w-content focus:outline-none desktop:max-w-[720px]"
            >
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </main>

            {{-- Inhoudsopgave (TOC) — alleen op desktop ≥1280. --}}
            <aside
                aria-label="Inhoudsopgave"
                class="hidden desktop:block"
            >
                @hasSection('toc')
                    @yield('toc')
                @endif
            </aside>
        </div>

        <footer
            role="contentinfo"
            class="border-t border-hairline py-6 text-body-sm text-steel"
        >
            <div class="flex flex-col items-start justify-between gap-2 tablet:flex-row tablet:items-center">
                <p>&copy; {{ date('Y') }} LaVita Urenregistratie</p>
                <p>
                    <a href="{{ url('/privacy') }}" class="text-steel underline">Privacyverklaring</a>
                    <span aria-hidden="true"> · </span>
                    <a href="{{ url('/toegankelijkheid') }}" class="text-steel underline">Toegankelijkheid</a>
                </p>
            </div>
        </footer>
    </div>

    {{-- Globale toast-container (taak 1.1): luistert naar @toast.window events. --}}
    <x-ui.toast />

    @livewireScripts

    {{-- ApexCharts via CDN (geen npm build nodig op shared hosting) --}}
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3/dist/apexcharts.min.js" defer></script>

    @stack('scripts')
</body>
</html>
