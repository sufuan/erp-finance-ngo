// Tiny i18n store. Translations arrive as Inertia shared props (decoded
// server-side from lang/{locale}.json) and are cached in localStorage
// keyed by translations_version, so the full file is not re-downloaded
// on every Inertia response — only when the version changes.

let current = { locale: 'en', translations: {} };

export function initI18n({ locale, translations, translations_version }) {
    const cachedVersion = localStorage.getItem('tpda_translations_version');
    const cachedLocale = localStorage.getItem('tpda_translations_locale');

    if (
        translations &&
        translations_version &&
        (translations_version !== cachedVersion || locale !== cachedLocale)
    ) {
        localStorage.setItem('tpda_translations', JSON.stringify(translations));
        localStorage.setItem('tpda_translations_version', translations_version);
        localStorage.setItem('tpda_translations_locale', locale);
        current = { locale, translations };
        return;
    }

    if (translations) {
        current = { locale, translations };
        return;
    }

    // Fall back to the localStorage cache (e.g. first paint before props).
    try {
        const cached = JSON.parse(localStorage.getItem('tpda_translations') || '{}');
        current = { locale: cachedLocale || locale || 'en', translations: cached };
    } catch {
        current = { locale: locale || 'en', translations: {} };
    }
}

export function getLocale() {
    return current.locale;
}

/**
 * A master record's display name in the current UI language: the Bangla
 * name when the locale is Bangla and one is set, otherwise the English
 * name (owner order 2026-11-18, e.g. deposit product names).
 */
export function localizedName(item) {
    if (!item) return '';
    return getLocale() === 'bn' && item.name_bn ? item.name_bn : (item.name_en ?? '');
}

export function t(key, replacements = {}) {
    let value = current.translations[key] ?? key;
    for (const [name, replacement] of Object.entries(replacements)) {
        value = value.replaceAll(`:${name}`, String(replacement));
    }
    return value;
}
