/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
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
