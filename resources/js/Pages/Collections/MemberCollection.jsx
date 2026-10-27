import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CollectionGrid from '../../Components/CollectionGrid';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Member Collection (Step 21): one member's accounts + summary + submit. */
export default function MemberCollection({ member, memberQuery, grid, businessDate }) {
    const { props } = usePage();
    const [searching, setSearching] = useState(false);

    const lookup = (e) => {
        e.preventDefault();
        const needle = e.target.elements.member.value;
        router.get('/collection/member', { member: needle }, {
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('member_collection')}</h1>
            <Card className="mb-4">
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('member_id')} required>
                            <input name="member" className={inputCls} defaultValue={memberQuery} required />
                        </Field>
                    </div>
                    <Field label={t('transaction_date')}>
                        <input type="date" className={inputCls} value={businessDate ?? ''} readOnly />
                    </Field>
                    <PrimaryButton type="submit" loading={searching}>{t('search_details')}</PrimaryButton>
                </form>
                {member && (
                    <p className="mt-3 text-sm">
                        <span className="font-medium">{member.member_no}</span> — {member.name}
                    </p>
                )}
            </Card>

            {member && (
                <CollectionGrid
                    grid={grid}
                    postUrl="/collection/member"
                    extra={{ member_id: member.id }}
                    submitLabel={t('submit')}
                    errors={props.errors}
                />
            )}
        </AppLayout>
    );
}
