export default {
  content: [
    './templates/**/*.{html,twig}',
    './assets/vue/**/*.{vue,js}',
    './assets/vue/controllers/shop/**/*.{vue,js}',
    './node_modules/element-plus/es/components/**/*.vue',
  ],
  theme: {
    container: { center: true, padding: '1rem', screens: { xl: '1200px', '2xl': '1500px' } },
    extend: {
      colors: {
        primary: '#CB261C',
        slate: { 950: '#0b1220' },
      },
      fontFamily: {
        sans: ['Inter', 'PingFang SC', 'Microsoft YaHei', 'Helvetica Neue', 'Arial', 'sans-serif'],
      },
    },
  },
  plugins: [],
}