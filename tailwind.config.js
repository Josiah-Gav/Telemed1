import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // Registers the brand tokens already defined as CSS variables in
            // resources/css/app.css (:root) so they work as full Tailwind
            // colors — hover:/focus-visible: variants and /opacity modifiers
            // (e.g. `text-brand-green-soft`, `ring-brand-gold`, `border-brand-green/20`)
            // — everywhere in the app, not just the literal utility classes
            // app.css hand-defines. Values must stay in sync with app.css.
            colors: {
                brand: {
                    green: '#0f6b3d',
                    'green-deep': '#0a4d2d',
                    'green-soft': '#edf8f0',
                    gold: '#d9b648',
                    'gold-soft': '#fff7dc',
                    border: '#dfe9e0',
                    muted: '#f4f7f4',
                },
                clsu: {
                    green: '#008000',
                    gold: '#FFD700',
                },
            },
        },
    },

    plugins: [forms],
};
