{{--
  Livewire-view — `Settings\SettingsOverview`

  Overzichtspagina voor instellingen met links naar secties.
--}}
@php
    $sections = $this->getSections();
@endphp

<div class="flex flex-col gap-4" data-livewire-component="settings.settings-overview">
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 font-semibold text-ink">Instellingen</h1>
                <p class="text-body-sm text-steel">
                    Beheer de configuratie van {{ $organizationName ?: 'je organisatie' }}
                </p>
            </div>
        </x-slot:header>

        @if (empty($sections))
            <p class="text-body-md text-steel">
                Er zijn geen instellingen beschikbaar voor jouw rol.
            </p>
        @else
            <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
                @foreach ($sections as $section)
                    <a
                        href="{{ $section['url'] }}"
                        class="group flex flex-col gap-2 rounded-input border-2 border-hairline p-4 no-underline transition-colors hover:border-brand-green hover:bg-surface/50"
                    >
                        <span class="text-button-md font-semibold text-ink group-hover:text-brand-green">
                            {{ $section['label'] }}
                        </span>
                        <span class="text-body-sm text-steel">
                            {{ $section['description'] }}
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </x-ui.card>
</div>
