/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  darkMode: 'media',
  theme: {
    extend: {
      colors: {
        // Design-system tokens - each backed by a CSS custom property so
        // dark mode (see src/index.css's prefers-color-scheme block) is a
        // second, deliberately-chosen palette rather than an inversion of
        // this one. Keep the legacy `navy` scale for now (still referenced
        // by a couple of untouched call sites) but new work should use
        // these semantic names, not navy/gray.
        canvas: 'var(--canvas)',
        surface: 'var(--surface)',
        sidebar: 'var(--sidebar)',
        border: 'var(--border)',
        indigo: {
          DEFAULT: 'var(--indigo)',
          tint: 'var(--indigo-tint)',
        },
        gold: {
          DEFAULT: 'var(--gold)',
          bg: 'var(--gold-bg)',
          // Text-safe gold: the base --gold value (#A9822E) does not clear
          // 4.5:1 for small text even on --gold-bg (measured 3.10:1) - this
          // darker variant is what small gold-toned text actually uses. See
          // the design-system report for the full contrast audit.
          ink: 'var(--gold-ink)',
        },
        success: { DEFAULT: 'var(--success)', bg: 'var(--success-bg)' },
        warning: { DEFAULT: 'var(--warning)', bg: 'var(--warning-bg)' },
        error: { DEFAULT: 'var(--error)', bg: 'var(--error-bg)' },
        ink: {
          DEFAULT: 'var(--ink)',
          soft: 'var(--ink-soft)',
          faint: 'var(--ink-faint)',
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
        // Display/editorial italic - the ONE serif-italic accent moment
        // (dashboard greeting only). Never used for UI chrome/body text.
        display: ['Newsreader', 'ui-serif', 'Georgia', 'serif'],
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
