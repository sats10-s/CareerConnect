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
        surface: {
          DEFAULT: 'var(--color-surface)',
          // Sidebar/dropdown hover states on dark surfaces (e.g.
          // Sidebar.tsx's nav-item hover) - a hair lighter than
          // --color-surface itself, same value as --color-surface-raised.
          raised: 'var(--color-surface-raised)',
        },
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
        // Body copy, UI chrome, everything except headings.
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        // Fonts/graphics polish pass: the reference's headlines read
        // tighter and more geometric than Inter renders at display
        // sizes - Space Grotesk (loaded in index.html) is the
        // dedicated display face now, used via font-display on
        // hero/page headings. serif kept pointed at Inter as a safety
        // net for any stray font-serif class this pass missed.
        serif: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        display: ['Space Grotesk', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      borderRadius: {
        // Buttons/badges/inputs shape tier (unchanged by the dark
        // pass - only buttons/badges became fully round, via rounded-
        // full in index.css's .btn/.badge-eyebrow, not this token).
        xl: '12px',
      },
      boxShadow: {
        // Same values as index.css's --shadow-glow/--shadow-glow-lg,
        // exposed as Tailwind utilities (shadow-glow / shadow-glow-lg)
        // for one-off mint-glow accents outside the .btn-primary/
        // .card-featured component classes.
        glow: '0 0 0 1px rgba(62, 207, 142, 0.45), 0 8px 28px -6px rgba(62, 207, 142, 0.55)',
        'glow-lg': '0 0 0 1px rgba(62, 207, 142, 0.5), 0 12px 40px -8px rgba(62, 207, 142, 0.65)',
        // Hover-intensified state for .card-interactive - see index.css.
        'glow-strong': '0 0 0 1px rgba(62, 207, 142, 0.65), 0 14px 44px -6px rgba(62, 207, 142, 0.8)',
      },
      keyframes: {
        'fade-in': {
          '0%': { opacity: '0', transform: 'translateY(4px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        // Fonts/graphics polish pass - continuous, subtle motion for
        // decorative elements (hero floating cards, auth-panel blobs),
        // not one-shot entrances like the others below.
        float: {
          '0%, 100%': { transform: 'translateY(0)' },
          '50%': { transform: 'translateY(-8px)' },
        },
        'pulse-glow': {
          '0%, 100%': { boxShadow: '0 0 0 1px rgba(62, 207, 142, 0.4), 0 8px 24px -6px rgba(62, 207, 142, 0.45)' },
          '50%': { boxShadow: '0 0 0 1px rgba(62, 207, 142, 0.55), 0 10px 34px -6px rgba(62, 207, 142, 0.75)' },
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
        float: 'float 5s ease-in-out infinite',
        'pulse-glow': 'pulse-glow 2.8s ease-in-out infinite',
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
