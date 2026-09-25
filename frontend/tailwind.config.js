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
          // Frontend Phase 1: the new spec defines three text tiers
          // (primary/secondary/muted) - faint now maps to its own
          // --color-faint token instead of aliasing --color-muted. See
          // src/index.css's token comment for its measured contrast
          // ceiling (use for metadata/timestamps, never body copy).
          faint: 'var(--color-faint)',
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
        // Drawer.tsx's edge-anchored panels.
        'slide-in-right': {
          '0%': { transform: 'translateX(100%)' },
          '100%': { transform: 'translateX(0)' },
        },
        'slide-in-left': {
          '0%': { transform: 'translateX(-100%)' },
          '100%': { transform: 'translateX(0)' },
        },
        // Toast.tsx entrance only - deliberately its own keyframe
        // rather than reusing slide-in-right, which has no opacity
        // fade (Drawer's full-height panel doesn't need one; a toast,
        // per the motion system spec, needs "slide+fade").
        'toast-in': {
          '0%': { opacity: '0', transform: 'translateX(16px)' },
          '100%': { opacity: '1', transform: 'translateX(0)' },
        },
      },
      animation: {
        'fade-in': 'fade-in 0.18s cubic-bezier(0.16, 1, 0.3, 1)',
        'slide-in-right': 'slide-in-right 0.2s ease-out',
        'slide-in-left': 'slide-in-left 0.2s ease-out',
        'toast-in': 'toast-in 0.18s cubic-bezier(0.16, 1, 0.3, 1)',
      },
      // Motion system shared timing scale - see src/styles/motion.ts
      // for the JS-side equivalents (stagger delays, count-up/progress
      // durations) components should import instead of hardcoding their
      // own numbers.
      transitionDuration: {
        fast: '120ms',
        base: '180ms',
        slow: '400ms',
      },
      transitionTimingFunction: {
        out: 'cubic-bezier(0.16, 1, 0.3, 1)',
      },
    },
  },
  plugins: [],
}
