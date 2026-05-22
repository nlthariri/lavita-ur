{{--
  Shared UI atom — `<x-ui.modal>` (taak 1.2 spec lavita-urenregistratie).

  Bron:
  - design.md § Components and Interfaces > `<x-ui.modal>`:
      Props: title (string), size (sm|md|lg), show (bool via Alpine.js).
      Animatie: scale 95%→100% + opacity, 200ms transition.
      Focus-trap via @focusin handler. Escape sluit modal. Backdrop click sluit modal.
  - requirements.md 12.2, 12.8, 12.9:
      ARIA: role="dialog", aria-modal="true", aria-labelledby verwijst naar title.
      Escape-toets om te sluiten. WCAG 2.1 AA compliance.
  - design.md § Design tokens:
      Sizes: sm = max-w-sm (384px), md = max-w-lg (512px), lg = max-w-2xl (672px).

  Props:
    - title     string   Modal-titel (getoond in header, gebruikt voor aria-labelledby)
    - size      string   sm | md | lg   (default: md)
    - show      string   Alpine.js model-expressie die open/dicht bepaalt (default: 'open')

  Slots:
    - default   Body-inhoud van de modal
    - footer    Optionele footer (bijv. actie-knoppen)

  Gebruik:
      <div x-data="{ showModal: false }">
          <x-ui.button @click="showModal = true">Open</x-ui.button>

          <x-ui.modal title="Bevestiging" size="sm" show="showModal">
              <p>Weet je het zeker?</p>

              <x-slot:footer>
                  <x-ui.button @click="showModal = false">Sluiten</x-ui.button>
              </x-slot:footer>
          </x-ui.modal>
      </div>

  Toegankelijkheid:
    - role="dialog" + aria-modal="true" voor screenreaders.
    - aria-labelledby verwijst naar het title-element (uniek id).
    - Focus-trap: tabben blijft binnen de modal zolang deze open is.
    - Escape-toets sluit de modal.
    - Backdrop-click sluit de modal.
    - Focus-ring via globale :focus-visible styling (NFR-1).
--}}
@props([
    'title' => '',
    'size' => 'md',
    'show' => 'open',
])

@php
    $id = 'modal-' . Str::random(8);
    $titleId = $id . '-title';

    $sizeClasses = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-lg',
        'lg' => 'max-w-2xl',
    ];

    $sizeClass = $sizeClasses[$size] ?? $sizeClasses['md'];
@endphp

<div
    x-show="{{ $show }}"
    x-cloak
    x-on:keydown.escape.window="if ({{ $show }}) { {{ $show }} = false }"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
    class="fixed inset-0 z-50 overflow-y-auto"
    {{ $attributes }}
>
    {{-- Backdrop --}}
    <div
        x-show="{{ $show }}"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-on:click="{{ $show }} = false"
        class="fixed inset-0 bg-ink/50"
        aria-hidden="true"
    ></div>

    {{-- Centering wrapper --}}
    <div class="flex min-h-full items-center justify-center p-4">
        {{-- Modal panel --}}
        <div
            x-show="{{ $show }}"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            x-on:click.stop
            x-on:focusin="
                if (! $el.contains($event.target)) {
                    const focusable = $el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\'-1\'])');
                    if (focusable.length) focusable[0].focus();
                }
            "
            x-init="$watch('{{ $show }}', value => {
                if (value) {
                    document.body.style.overflow = 'hidden';
                    $nextTick(() => {
                        const focusable = $el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\'-1\'])');
                        if (focusable.length) focusable[0].focus();
                    });
                } else {
                    document.body.style.overflow = '';
                }
            })"
            class="relative w-full {{ $sizeClass }} bg-canvas border border-hairline rounded-card p-6 shadow-xl"
        >
            {{-- Header --}}
            <div class="flex items-center justify-between mb-4 pb-4 border-b border-hairline">
                <h2 id="{{ $titleId }}" class="text-lg font-semibold text-ink">
                    {{ $title }}
                </h2>

                {{-- Sluit-knop --}}
                <button
                    type="button"
                    x-on:click="{{ $show }} = false"
                    class="inline-flex items-center justify-center rounded-button p-1.5 text-steel hover:text-ink hover:bg-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 transition-colors"
                    aria-label="Modal sluiten"
                >
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Body --}}
            <div class="text-body-md text-ink">
                {{ $slot }}
            </div>

            {{-- Footer (optioneel) --}}
            @isset($footer)
                <footer class="mt-4 pt-4 border-t border-hairline">
                    {{ $footer }}
                </footer>
            @endisset
        </div>
    </div>
</div>
