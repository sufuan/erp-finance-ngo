import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CollectionGrid from '../../Components/CollectionGrid';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/**
 * Simple Sheet Posting (Step 21): samity (optionally narrowed to one
 * member) with a flat editable amount grid — one column per account
 * kind — and a Post Transaction submit.
 */
export default function SimpleSheetPosting({ samities, samityId, memberQuery, grid, businessDate }) {
    const { props } = usePage();
    const [searching, setSearching] = useState(false);
    const [selected, setSelected] = useState(samityId ?? '');

    const search = (e) => {
        e.preventDefault();
        const needle = e.target.elements.member?.value ?? '';
        router.get('/collection/simple-sheet', { samity_id: selected, member: needle }, {
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('simple_sheet_posting')}</h1>
            <Card className="mb-4">
                <form onSubmit={search} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-64 flex-1">
                        <Field label={t('samity_name')} required>
                            <select className={inputCls} value={selected} onChange={(e) => setSelected(e.target.value)} required>
                                <option value="">—</option>
                                {samities.map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}
                            </select>
                        </Field>
                    </div>
                    <div className="min-w-48 flex-1">
                        <Field label={t('member_id')}>
                            <input name="member" className={inputCls} defaultValue={memberQuery} />
                        </Field>
                    </div>
                    <Field label={t('transaction_date')}>
                        <input type="date" className={inputCls} value={businessDate ?? ''} readOnly />
                    </Field>
                    <PrimaryButton type="submit" loading={searching}>{t('search_simple_sheet')}</PrimaryButton>
                </form>
            </Card>

            {samityId && (
                <CollectionGrid
                    grid={grid}
                    postUrl={`/collection/simple-sheet?samity_id=${samityId}`}
                    submitLabel={t('post_transaction')}
                    errors={props.errors}
                />
            )}
        </AppLayout>
    );
}
