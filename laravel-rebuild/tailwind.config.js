/**
 * Tailwind CSS 3 — design tokens voor LaVita Urenregistratie.
 *
 * Bron: `.kiro/specs/lavita-urenregistratie/design.md` § "Components and
 * Interfaces > Design tokens" en `requirements.md` NFR-3 / NFR-4 / 6.13.
 *
 * Aanpak:
 * - Kleuren, border-radii en fonts zijn _tokens_ (semantische namen) i.p.v.
 *   utility-overrides; alle UI gebruikt uitsluitend deze tokens.
 * - `screens` overschrijft de Tailwind-defaults volledig zodat de drie
 *   breakpoints uit het ontwerp (mobiel <768, tablet 768-1279, desktop ≥1280)
 *   één-op-één matchen. Mobiel is de impliciete default (`min-width: 0`).
 * - `content`-globs dekken zowel Blade-views, Livewire 3-components
 *   (na taak 8.3) als losse JS-bundle-points.
 *
 * `package.json` heeft `"type": "module"`, dus dit bestand is een ES-module
 * met `export default`.
 */

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/Livewire/**/*.php',
        // Vendor Livewire/Blade-componenten die Tailwind-classes uitsturen
        // (bv. paginatie-views) bij compileren meenemen.
        './vendor/livewire/livewire/dist/livewire.js',
    ],
    theme: {
        // Volledige override van de defaults — UI-grid wordt door deze
        // drie breakpoints gedragen (zie design.md § Grid).
        screens: {
            tablet: '768px',
            desktop: '1280px',
        },
        extend: {
            colors: {
                // Brand- en oppervlakte-tokens
                canvas: '#FFFFFF',
                primary: {
                    DEFAULT: '#0a0a0a',
                    foreground: '#FFFFFF',
                },
                'on-primary': '#FFFFFF',
                'brand-green': '#00d4a4',
                surface: '#f7f7f7',
                hairline: '#e5e5e5',
                ink: '#0a0a0a',
                steel: '#5a5a5c',

                // Semantische statuskleuren (badges + ATW-signalen).
                success: {
                    DEFAULT: '#00d4a4',
                    bg: '#DCFCE7',
                    fg: '#166534',
                },
                warning: {
                    DEFAULT: '#f59e0b',
                    bg: '#FEF9C3',
                    fg: '#854D0E',
                },
                danger: {
                    DEFAULT: '#ef4444',
                    bg: '#FEE2E2',
                    fg: '#991B1B',
                },
                concept: {
                    bg: '#f7f7f7',
                    fg: '#5a5a5c',
                },
            },
            borderRadius: {
                // Component-tokens uit design.md.
                button: '9999px',
                card: '12px',
                input: '8px',
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
                mono: ['"Geist Mono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
            },
            fontSize: {
                // Typografische tokens uit design.md (heading-2, body-md, body-sm, button-md).
                'heading-2': ['36px', { lineHeight: '1.20', fontWeight: '600' }],
                'body-md': ['16px', { lineHeight: '1.50', fontWeight: '400' }],
                'body-sm': ['14px', { lineHeight: '1.50', fontWeight: '400' }],
                'button-md': ['14px', { lineHeight: '1.30', fontWeight: '500' }],
            },
            maxWidth: {
                // Maximum content-breedte volgens grid-tokens.
                content: '1280px',
            },
            spacing: {
                // Vaste sidebar/TOC-breedtes uit het 3-koloms desktop-grid.
                sidebar: '240px',
                toc: '200px',
                gutter: '32px',
            },
            ringColor: {
                // Focus-ring matcht `text-input:focus` token.
                DEFAULT: '#00d4a4',
            },
        },
    },
    plugins: [],
};
