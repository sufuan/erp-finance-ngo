import '@fontsource/hind-siliguri/300.css';
import '@fontsource/hind-siliguri/400.css';
import '@fontsource/hind-siliguri/500.css';
import '@fontsource/hind-siliguri/600.css';
import '@fontsource/hind-siliguri/700.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import ErrorBoundary from './Components/ErrorBoundary';
import { initI18n } from './lib/i18n';
import { withI18n } from './lib/withI18n';

// Night mode: restore before first paint to avoid a flash.
if (localStorage.getItem('tpda_theme') === 'dark') {
    document.documentElement.classList.add('dark');
}

createInertiaApp({
    title: (title, page) => {
        const appName = page?.props?.appName || 'TPDA e-Sheba';
        return title ? `${title} - ${appName}` : appName;
    },
    resolve: async (name) => {
        const mod = await resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        );
        // Sync the i18n store from the new page's props BEFORE the page
        // body renders (see lib/withI18n) — no sidebar/content split.
        return withI18n(mod.default ?? mod);
    },
    setup({ el, App, props }) {
        initI18n({
            locale: props.initialPage.props.locale,
            translations: props.initialPage.props.translations,
            translations_version: props.initialPage.props.translations_version,
        });
        const root = createRoot(el);
        root.render(
            <ErrorBoundary>
                <App {...props} />
            </ErrorBoundary>,
        );
    },
    progress: {
        color: '#16a34a',
    },
});
