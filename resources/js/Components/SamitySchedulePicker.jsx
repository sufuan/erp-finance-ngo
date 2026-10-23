import { Field } from './ui';
import { t } from '../lib/i18n';

/**
 * Samity meeting schedule picker: a samity meets EITHER weekly (a weekday)
 * OR monthly (a date 1–31, or 0 = last day of the month). One control drives
 * the meeting_frequency + samity_day + date_of_month form fields, so the
 * inactive field is always cleared and the payload can never mix both.
 */

/** Storage token for a schedule: day name, "monthly:15", or "monthly:last". */
export const scheduleTokenOf = (samity) => {
    if (!samity) return null;
    const date = samity.date_of_month;
    if (date !== null && date !== undefined && date !== '') {
        return Number(date) === 0 ? 'monthly:last' : `monthly:${Number(date)}`;
    }

    return samity.samity_day ?? null;
};

/** Human label for a schedule token (used in lists, current value, history). */
export const scheduleLabel = (token) => {
    if (token === null || token === undefined || token === '') return '—';
    if (typeof token === 'string' && token.startsWith('monthly:')) {
        const v = token.slice('monthly:'.length);

        return v === 'last' ? `${t('monthly_prefix')} — ${t('last_day_of_month')}` : `${t('monthly_prefix')} — ${v}`;
    }

    return t(`day_${String(token).toLowerCase()}`);
};

const chipCls = (active) => `min-h-[44px] rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${
    active
        ? 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-500 dark:bg-emerald-500'
        : 'border-zinc-300 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800'
}`;

export default function SamitySchedulePicker({ frequency, day, dateOfMonth, days, onFrequency, onDay, onDate, errors = {} }) {
    const dates = Array.from({ length: 31 }, (_, i) => i + 1);

    return (
        <div>
            <Field label={t('meeting_frequency')} required error={errors.meeting_frequency}>
                <div className="flex gap-2">
                    {[['weekly', 'weekly'], ['monthly', 'monthly']].map(([value, key]) => (
                        <button key={value} type="button" onClick={() => onFrequency(value)}
                            className={`min-h-[44px] flex-1 rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${
                                frequency === value
                                    ? 'border-emerald-600 bg-emerald-50 text-emerald-700 dark:border-emerald-500 dark:bg-emerald-950 dark:text-emerald-400'
                                    : 'border-zinc-300 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800'}`}
                            aria-pressed={frequency === value}>
                            {t(key)}
                        </button>
                    ))}
                </div>
            </Field>

            {frequency === 'weekly' && (
                <Field label={t('samity_day')} required error={errors.samity_day}>
                    <div className="flex flex-wrap gap-2">
                        {(days ?? []).map((d) => (
                            <button key={d.id} type="button" onClick={() => onDay(d.id)}
                                className={chipCls(day === d.id)} aria-pressed={day === d.id}>
                                {d.label_key ? t(d.label_key) : d.name}
                            </button>
                        ))}
                    </div>
                </Field>
            )}

            {frequency === 'monthly' && (
                <Field label={t('date_of_month')} required error={errors.date_of_month}>
                    <div>
                        <div className="grid grid-cols-7 gap-2 sm:grid-cols-10">
                            {dates.map((n) => (
                                <button key={n} type="button" onClick={() => onDate(String(n))}
                                    className={chipCls(String(dateOfMonth ?? '') === String(n))} aria-pressed={String(dateOfMonth ?? '') === String(n)}>
                                    {n}
                                </button>
                            ))}
                        </div>
                        <button type="button" onClick={() => onDate('0')}
                            className={`${chipCls(String(dateOfMonth ?? '') === '0')} mt-2 w-full sm:w-auto`}
                            aria-pressed={String(dateOfMonth ?? '') === '0'}>
                            {t('last_day_of_month')}
                        </button>
                        <p className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{t('monthly_meeting_hint')}</p>
                    </div>
                </Field>
            )}
        </div>
    );
}
