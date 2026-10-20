import '@fontsource/hind-siliguri/300.css';
import '@fontsource/hind-siliguri/400.css';
import '@fontsource/hind-siliguri/500.css';
import '@fontsource/hind-siliguri/600.css';
import '@fontsource/hind-siliguri/700.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { initI18n } from './lib/i18n';

// Night mode: restore before first paint to avoid a flash.
if (localStorage.getItem('tpda_theme') === 'dark') {
    document.documentElement.classList.add('dark');
}

createInertiaApp({
    title: (title, page) => {
        const appName = page?.props?.appName || 'TPDA e-Sheba';
        return title ? `${title} - ${appName}` : appName;
    },
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        initI18n({
            locale: props.initialPage.props.locale,
            translations: props.initialPage.props.translations,
            translations_version: props.initialPage.props.translations_version,
        });
        const root = createRoot(el);
        root.render(<App {...props} />);
    },
    progress: {
        color: '#16a34a',
    },
});
