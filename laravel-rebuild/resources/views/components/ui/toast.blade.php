{{--
  Shared UI atom — `<x-ui.toast>` (taak 1.1 spec lavita-urenregistratie).

  Bron:
  - design.md § "Components and Interfaces > Nieuwe UI-atoms > <x-ui.toast>"
  - requirements.md 3.1–3.10, 12.1

  Globale toast-container met Alpine.js `toastManager()`. Wordt eenmalig
  geplaatst in de layout (layouts/app.blade.php en components/layouts/app.blade.php).
  Luistert naar `@toast.window` events gedispatcht door Livewire-componenten
  via `$this->dispatch('toast', variant: '...', message: '...')`.

  Kenmerken:
  - Queue-logica: maximaal 3 toasts tegelijk zichtbaar.
  - Auto-dismiss: 5s (success/warning/info), 8s (error).
  - Hover pauzeert de auto-dismiss timer.
  - Slide-in animatie van rechts (translate-x), fade-out bij dismiss.
  - Positioning: fixed top-4 right-4 (desktop), fixed top-4 inset-x-4 (mobiel).
  - ARIA: role="alert", aria-live="polite", sluit-knop met aria-label="Melding sluiten".

  Props:
    - variant   string   success | error | warning | info   (default: info)
    - message   string   Berichttekst                       (default: '')
    - duration  int      Auto-dismiss in ms                 (default: 5000, 8000 voor error)

  Gebruik:
    Plaats `<x-ui.toast />` eenmalig in de layout. Trigger via Livewire:
      $this->dispatch('toast', variant: 'success', message: 'Werkregel opgeslagen');

  Of via Alpine.js:
      $dispatch('toast', { variant: 'error', message: 'Er ging iets mis' });
--}}
@props([
    'variant' => 'info',
    'message' => '',
    'duration' => 5000,
])

<div
    x-data="toastManager()"
    x-on:toast.window="addToast($event.detail)"
    class="pointer-events-none fixed inset-x-4 top-4 z-50 flex flex-col gap-2 tablet:inset-x-auto tablet:right-4 tablet:w-80"
    aria-label="Meldingen"
>
    <template x-for="toast in visibleToasts" :key="toast.id">
        <div
            x-show="toast.visible"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full opacity-0"
            x-transition:enter-end="translate-x-0 opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0 opacity-100"
            x-transition:leave-end="translate-x-full opacity-0"
            x-on:mouseenter="pauseToast(toast.id)"
            x-on:mouseleave="resumeToast(toast.id)"
            role="alert"
            aria-live="polite"
            class="pointer-events-auto flex items-start gap-3 rounded-card border p-4 shadow-lg"
            x-bind:class="variantClasses(toast.variant)"
        >
            {{-- Icoon per variant --}}
            <span class="mt-0.5 flex-shrink-0" aria-hidden="true" x-html="variantIcon(toast.variant)"></span>

            {{-- Bericht --}}
            <p class="flex-1 text-body-sm text-ink" x-text="toast.message"></p>

            {{-- Sluit-knop --}}
            <button
                type="button"
                x-on:click="removeToast(toast.id)"
                class="flex-shrink-0 rounded p-1 text-steel hover:text-ink focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 focus:outline-none"
                aria-label="Melding sluiten"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true" focusable="false">
                    <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/>
                </svg>
            </button>
        </div>
    </template>
</div>

@once
@push('scripts')
<script>
/**
 * Alpine.js toastManager — globale toast-queue met auto-dismiss en hover-pause.
 *
 * Getriggerd door Livewire dispatch('toast', { variant, message, duration? })
 * of Alpine $dispatch('toast', { variant, message, duration? }).
 *
 * Kenmerken:
 * - Max 3 zichtbare toasts tegelijk (queue voor overflow).
 * - Auto-dismiss: 5000ms (success/warning/info), 8000ms (error).
 * - Hover pauzeert de countdown-timer.
 * - Verticale stapeling met 8px gap (via Tailwind gap-2).
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('toastManager', () => ({
        toasts: [],
        nextId: 0,
        maxVisible: 3,

        get visibleToasts() {
            return this.toasts.slice(0, this.maxVisible);
        },

        addToast(detail) {
            // Livewire 3 dispatcht events als array met één object, of als object direct.
            const data = Array.isArray(detail) ? detail[0] : detail;
            const variant = data.variant || 'info';
            const message = data.message || '';
            const defaultDuration = variant === 'error' ? 8000 : 5000;
            const duration = data.duration || defaultDuration;

            const id = this.nextId++;
            const toast = {
                id,
                variant,
                message,
                duration,
                visible: true,
                paused: false,
                remaining: duration,
                timer: null,
                startedAt: null,
            };

            this.toasts.push(toast);
            this.startTimer(toast);
        },

        startTimer(toast) {
            toast.startedAt = Date.now();
            toast.timer = setTimeout(() => {
                this.removeToast(toast.id);
            }, toast.remaining);
        },

        pauseToast(id) {
            const toast = this.toasts.find(t => t.id === id);
            if (!toast || toast.paused) return;

            toast.paused = true;
            clearTimeout(toast.timer);
            toast.timer = null;
            // Bereken resterende tijd
            const elapsed = Date.now() - toast.startedAt;
            toast.remaining = Math.max(0, toast.remaining - elapsed);
        },

        resumeToast(id) {
            const toast = this.toasts.find(t => t.id === id);
            if (!toast || !toast.paused) return;

            toast.paused = false;
            this.startTimer(toast);
        },

        removeToast(id) {
            const toast = this.toasts.find(t => t.id === id);
            if (!toast) return;

            // Fade-out trigger
            toast.visible = false;
            clearTimeout(toast.timer);

            // Verwijder na animatie (200ms leave-duration)
            setTimeout(() => {
                this.toasts = this.toasts.filter(t => t.id !== id);
            }, 250);
        },

        variantClasses(variant) {
            const classes = {
                success: 'border-brand-green/30 bg-success-bg',
                error: 'border-danger/30 bg-danger-bg',
                warning: 'border-warning/30 bg-warning-bg',
                info: 'border-hairline bg-canvas',
            };
            return classes[variant] || classes.info;
        },

        variantIcon(variant) {
            const icons = {
                success: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#00d4a4" class="h-5 w-5"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>',
                error: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#ef4444" class="h-5 w-5"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>',
                warning: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#f59e0b" class="h-5 w-5"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>',
                info: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#3b82f6" class="h-5 w-5"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/></svg>',
            };
            return icons[variant] || icons.info;
        },
    }));
});
</script>
@endpush
@endonce
