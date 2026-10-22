import { usePage } from '@inertiajs/react';
import { initI18n } from './i18n';

const cache = new WeakMap();

/**
 * Wrap a page component so the i18n store syncs from the CURRENT page
 * props during the wrapper's render — that is, BEFORE the wrapped page's
 * own function body runs.
 *
 * Why this exists: every page calls t() in its own body and returns
 * <AppLayout>…</AppLayout>. React executes the page body first and the
 * layout body second, so syncing the store inside the layout (the old
 * approach) updated it in time for the sidebar/header but one render too
 * late for the page content — after a language switch the sidebar showed
 * the new language while the content showed the previous one (an apparent
 * inversion), until some unrelated re-render. As the common ancestor of
 * page + layout, this wrapper syncs before either body executes, so a
 * switch renders sidebar and content in the new language in one pass.
 *
 * The WeakMap keeps the wrapper's component identity stable per page, so
 * Inertia visits that return to the same page don't remount it.
 */
export function withI18n(Page) {
    if (cache.has(Page)) {
        return cache.get(Page);
    }

    function I18nSyncedPage(props) {
        const { props: pageProps } = usePage();
        initI18n({
            locale: pageProps.locale,
            translations: pageProps.translations,
            translations_version: pageProps.translations_version,
        });

        return <Page {...props} />;
    }

    cache.set(Page, I18nSyncedPage);

    return I18nSyncedPage;
}
