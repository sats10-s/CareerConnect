/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  darkMode: 'media',
  theme: {
    extend: {
      colors: {
        // Institutional Ink design-system tokens - each backed by the
        // --color-* CSS custom properties defined in src/index.css. Use
        // these names (base/surface/ink/muted/hairline/primary/accent/
        // success/warning/error/info) for new work.
        base: 'var(--color-base)',
        surface: 'var(--color-surface)',
        muted: 'var(--color-muted)',
        hairline: 'var(--color-hairline)',
        ink: {
          DEFAULT: 'var(--color-ink)',
          soft: 'var(--color-muted)',
          // Retired as a separate lighter tier - the spec defines only
          // two text tiers (ink, muted). Kept as an alias, not a second
          // value, so existing text-ink-faint call sites don't need a
          // class-name change.
          faint: 'var(--color-muted)',
        },
        primary: {
          DEFAULT: 'var(--color-primary)',
          hov: 'var(--color-primary-hov)',
          tint: 'var(--color-primary-tint)',
        },
        accent: {
          DEFAULT: 'var(--color-accent)',
          str: 'var(--color-accent-str)',
          tint: 'var(--color-accent-tint)',
        },
        success: { DEFAULT: 'var(--color-success-text)', bg: 'var(--color-success-bg)' },
        warning: { DEFAULT: 'var(--color-warn-text)', bg: 'var(--color-warn-bg)' },
        error: { DEFAULT: 'var(--color-danger-text)', bg: 'var(--color-danger-bg)' },
        info: { DEFAULT: 'var(--color-info-text)', bg: 'var(--color-info-bg)' },

        // Legacy alias names, kept so every existing component/page
        // class (bg-canvas, bg-sidebar, bg-indigo, text-indigo,
        // bg-indigo-tint, bg-gold*, text-gold-ink, border-border)
        // keeps resolving with no component or page edits - per "do not
        // rewrite functionality" / "only change presentation." Each one
        // points at the exact same --color-* custom property as its
        // named-above counterpart; there is no second, independently
        // maintained value here.
        canvas: 'var(--color-base)',
        sidebar: 'var(--color-sidebar)',
        border: 'var(--color-hairline)',
        indigo: {
          DEFAULT: 'var(--color-primary)',
          tint: 'var(--color-primary-tint)',
        },
        gold: {
          DEFAULT: 'var(--color-accent)',
          bg: 'var(--color-accent-tint)',
          // Text-safe accent - see src/index.css's token comment for the
          // measured contrast ceiling of this exact spec value.
          ink: 'var(--color-accent-str)',
        },
        navy: {
          50: '#eef2fb',
          100: '#d7e0f5',
          200: '#b3c4ea',
          300: '#8aa3dd',
          400: '#5f7ecd',
          500: '#3f5fb8',
          600: '#2f4998',
          700: '#26397a',
          800: '#1f2f63',
          900: '#141d40',
          950: '#0c1229',
        },
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        // Page/hero titles only - see the accent-italic dashboard
        // greeting usage in src/index.css (.font-display-italic). Never
        // used for UI chrome or body text.
        serif: ['Source Serif 4', 'ui-serif', 'Georgia', 'serif'],
        display: ['Source Serif 4', 'ui-serif', 'Georgia', 'serif'],
      },
      borderRadius: {
        // Cards/modals per the Institutional Ink shape system (10-14px).
        // Tailwind's default `xl` (0.75rem / 12px) already sits inside
        // that range; named explicitly here so it reads as a deliberate
        // token, not a coincidence of Tailwind's default scale.
        xl: '12px',
      },
      keyframes: {
        'fade-in': {
          '0%': { opacity: '0', transform: 'translateY(4px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
      },
      animation: {
        'fade-in': 'fade-in 0.2s ease-out',
      },
    },
  },
  plugins: [],
}
