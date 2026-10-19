import defaultColors from 'tailwindcss/colors';

// Color definido mediante una variable CSS (canales RGB) para que cambie con el tema.
// Los valores de cada variable están en src/index.css (:root y .dark).
const themed = (name) => `rgb(var(--${name}) / <alpha-value>)`;

// Tonos claros (50-200) y oscuros (700-900) de un color que se invierten en modo oscuro.
// Los tonos intermedios (300-600) se mantienen fijos para botones, gráficos y degradados.
const themedTints = (name, base) => ({
  ...base,
  50: themed(`${name}-50`),
  100: themed(`${name}-100`),
  200: themed(`${name}-200`),
  700: themed(`${name}-700`),
  800: themed(`${name}-800`),
  900: themed(`${name}-900`),
});

const primary = {
  50: '#f5f7ff',
  100: '#ebf0ff',
  200: '#d6e0ff',
  300: '#b3c5ff',
  400: '#8ca5ff',
  500: '#667eea',
  600: '#5568d3',
  700: '#4553b8',
  800: '#374198',
  900: '#2d3475',
};

/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        // Fondo de tarjetas, modales, barra de navegación e inputs
        surface: themed('surface'),
        // Escala neutra completa: se invierte en modo oscuro
        gray: Object.fromEntries(
          [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((shade) => [
            shade,
            themed(`gray-${shade}`),
          ])
        ),
        red: themedTints('red', defaultColors.red),
        green: themedTints('green', defaultColors.green),
        blue: themedTints('blue', defaultColors.blue),
        yellow: themedTints('yellow', defaultColors.yellow),
        purple: themedTints('purple', defaultColors.purple),
        primary: themedTints('primary', primary),
        secondary: {
          50: '#faf5ff',
          100: '#f3e8ff',
          200: '#e9d5ff',
          300: '#d8b4fe',
          400: '#c084fc',
          500: '#a855f7',
          600: '#9333ea',
          700: '#7e22ce',
          800: '#6b21a8',
          900: '#581c87',
        }
      },
      fontFamily: {
        sans: ['-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
      boxShadow: {
        'soft': '0 2px 15px -3px rgba(0, 0, 0, 0.07), 0 10px 20px -2px rgba(0, 0, 0, 0.04)',
      }
    },
  },
  plugins: [],
}
