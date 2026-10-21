import {
    ArrowLeftRight, Ban, Banknote, Briefcase, Building2, CalendarDays, Clock3, FilePlus2,
    HandCoins, History, IdCard, Landmark, LayoutGrid, ListChecks, MapPin, Megaphone,
    Package, PiggyBank, Scale, ScrollText, Settings, ShieldCheck, Sun, Unlock, UploadCloud,
    UserPlus, Users, UserX, Wallet, XCircle,
} from 'lucide-react';

/**
 * Module hub definitions (redesign): one source for the Home mega hub and
 * the per-module hub pages. Only built features appear. `can` names a key
 * of the shared auth.can prop — links are hidden when the hint is false
 * (server routes remain authoritative). Tones are the prototype's formal
 * pastel/rich pairs.
 */
export const TONES = {
    blue: { bg: '#e3eefb', fg: '#2f7fd0' },
    teal: { bg: '#dff5f4', fg: '#0e9594' },
    green: { bg: '#e2f5e9', fg: '#1e9e62' },
    amber: { bg: '#fdf0dc', fg: '#d98a06' },
    orange: { bg: '#fdeeda', fg: '#e07b1f' },
    rose: { bg: '#fde7e7', fg: '#d64545' },
    violet: { bg: '#ece7fb', fg: '#6d4fc2' },
    cyan: { bg: '#e0f4fb', fg: '#0e7fa8' },
    pink: { bg: '#fbe7f2', fg: '#c2438f' },
    slate: { bg: '#e8edf3', fg: '#506274' },
};

const L = (href, labelKey, icon, tone, can) => ({ href, labelKey, icon, tone, can });

export const MODULES = [
    {
        key: 'member', titleKey: 'module_member', icon: Users, tone: 'blue',
        tiles: [
            L('/members/admission', 'member_admission', UserPlus, 'blue', 'members'),
            L('/members', 'member_list', ListChecks, 'teal', 'members'),
            L('/members/closing', 'member_closing', UserX, 'rose', 'members'),
            L('/settings/samities', 'samity_opening', Users, 'violet', 'settings'),
        ],
        cards: [
            {
                titleKey: 'member_operations',
                links: [
                    L('/members/admission', 'member_admission', UserPlus, 'blue', 'members'),
                    L('/members', 'member_list', ListChecks, 'teal', 'members'),
                    L('/members/closing', 'member_closing', UserX, 'rose', 'members'),
                    L('/members/transfer', 'member_transfer', ArrowLeftRight, 'cyan', 'members'),
                ],
            },
            {
                titleKey: 'samity_management',
                links: [
                    L('/settings/samities', 'samity_opening', Users, 'violet', 'settings'),
                    L('/settings/samities/changes/day', 'samity_day_change', CalendarDays, 'amber', 'settings'),
                    L('/settings/samities/changes/officer', 'samity_officer_change', ArrowLeftRight, 'cyan', 'settings'),
                    L('/settings/samities/changes/manager', 'samity_manager_change', ArrowLeftRight, 'cyan', 'settings'),
                    L('/settings/samities/merge', 'samity_merge', Users, 'slate', 'settings'),
                    L('/settings/samities/split', 'samity_split', Users, 'slate', 'settings'),
                ],
            },
            {
                titleKey: 'data_migration',
                links: [L('/settings/member-migrations', 'member_migrations', UploadCloud, 'orange', 'settings')],
            },
        ],
    },
    {
        key: 'deposit', titleKey: 'module_deposit', icon: PiggyBank, tone: 'teal',
        tiles: [
            L('/deposits/opening', 'deposit_account_opening', PiggyBank, 'green', 'deposits'),
            L('/deposits', 'account_list', LayoutGrid, 'blue', 'deposits'),
            L('/deposits/receive', 'deposit_receive', HandCoins, 'teal', 'deposits'),
            L('/deposits/payment', 'deposit_payment', Wallet, 'amber', 'deposits'),
            L('/deposits/products', 'deposit_products', Package, 'slate', 'deposits'),
        ],
        cards: [
            {
                titleKey: 'account_management',
                links: [
                    L('/deposits/opening', 'deposit_account_opening', PiggyBank, 'green', 'deposits'),
                    L('/deposits/other', 'other_account_opening', PiggyBank, 'cyan', 'deposits'),
                    L('/deposits', 'account_list', LayoutGrid, 'blue', 'deposits'),
                    L('/deposits/closing', 'deposit_closing', UserX, 'rose', 'deposits'),
                    L('/deposits/transfer', 'deposit_transfer', ArrowLeftRight, 'violet', 'deposits'),
                ],
            },
            {
                titleKey: 'deposit_transaction',
                links: [
                    L('/deposits/receive', 'deposit_receive', HandCoins, 'teal', 'deposits'),
                    L('/deposits/payment', 'deposit_payment', Wallet, 'amber', 'deposits'),
                    L('/deposits/share', 'share_transaction', ScrollText, 'pink', 'deposits'),
                    L('/deposits/blocks', 'transaction_block', Ban, 'rose', 'deposits'),
                    L('/deposits/blocks/clear', 'clear_block', Unlock, 'green', 'deposits'),
                ],
            },
            {
                titleKey: 'profit_management',
                links: [
                    L('/deposits/profit/setup', 'profit_setup', Settings, 'slate', 'deposits'),
                    L('/deposits/profit/profile', 'transaction_profile', ScrollText, 'cyan', 'deposits'),
                    L('/deposits/profit/distribution', 'profit_distribution', HandCoins, 'teal', 'deposits'),
                    L('/deposits/profit/distribution/auto', 'auto_profit_distribution', HandCoins, 'green', 'deposits'),
                    L('/deposits/profit/distributions', 'profit_distribution_list', ListChecks, 'blue', 'deposits'),
                    L('/deposits/profit/payment', 'profit_payment', Wallet, 'amber', 'deposits'),
                ],
            },
            {
                titleKey: 'nav_reports',
                links: [L('/reports/deposits', 'deposit_reports', ScrollText, 'violet', 'reports')],
            },
        ],
    },
    {
        key: 'loan', titleKey: 'module_loan', icon: Landmark, tone: 'amber',
        tiles: [
            L('/loans/proposals/create', 'loan_proposal', FilePlus2, 'violet', 'loans'),
            L('/loans/proposals', 'proposal_list', ListChecks, 'blue', 'loans'),
            L('/loans/opening', 'loan_opening', Landmark, 'green', 'loans'),
            L('/loans/receive', 'installment_receive', HandCoins, 'teal', 'loans'),
            L('/loans/disbursement', 'disbursement_list', Banknote, 'amber', 'loans'),
        ],
        cards: [
            {
                titleKey: 'account_opening_section',
                links: [
                    L('/loans/opening', 'loan_opening', Landmark, 'green', 'loans'),
                    L('/loans/products', 'loan_products', Package, 'slate', 'loans'),
                    L('/loans/proposals/create', 'loan_proposal', FilePlus2, 'violet', 'loans'),
                    L('/loans/proposals', 'proposal_list', ListChecks, 'blue', 'loans'),
                    L('/loans/disbursement', 'disbursement_list', Banknote, 'amber', 'loans'),
                    L('/loans/due-penalty', 'due_penalty_setup', Scale, 'rose', 'loans'),
                    L('/loans/installment-settings', 'installment_settings', Settings, 'cyan', 'loans'),
                ],
            },
            {
                titleKey: 'investment_transaction',
                links: [
                    L('/loans/receive', 'installment_receive', HandCoins, 'teal', 'loans'),
                    L('/loans/receive/history', 'receive_history', History, 'slate', 'loans'),
                    L('/loans/receive/cancel', 'receive_cancel', XCircle, 'rose', 'loans'),
                    L('/loans/insurance-claims', 'insurance_claims', ShieldCheck, 'cyan', 'loans'),
                ],
            },
            // Note: Loan Reschedule / Waiver / Additional Penalty / Write Off
            // are per-loan forms (?loan_id=…) reached from a loan's context;
            // linked bare they 404 by design, so they are not hub links.
            {
                titleKey: 'reports_migration',
                links: [
                    L('/reports/loans', 'loan_reports', ScrollText, 'violet', 'reports'),
                    L('/reports/llp', 'llp', Scale, 'orange', 'reports'),
                    L('/settings/balance-migrations', 'balance_migrations', UploadCloud, 'teal', 'settings'),
                ],
            },
        ],
    },
    {
        key: 'hrm', titleKey: 'module_hrm', icon: Briefcase, tone: 'violet',
        tiles: [
            L('/settings/employees', 'employees', IdCard, 'blue', 'employees'),
            L('/settings/directors', 'directors', Briefcase, 'slate', 'employees'),
            L('/hrm/departments', 'departments', Building2, 'teal', 'hrm'),
            L('/hrm/shifts', 'shifts', Clock3, 'amber', 'hrm'),
        ],
        cards: [
            {
                titleKey: 'hrm_masters',
                links: [
                    L('/hrm/departments', 'departments', Building2, 'teal', 'hrm'),
                    L('/hrm/designations', 'designations', IdCard, 'blue', 'hrm'),
                    L('/hrm/employee-types', 'employee_types', Users, 'violet', 'hrm'),
                    L('/hrm/shifts', 'shifts', Clock3, 'amber', 'hrm'),
                ],
            },
            {
                titleKey: 'employee_registers',
                links: [
                    L('/settings/employees', 'employees', IdCard, 'blue', 'employees'),
                    L('/settings/directors', 'directors', Briefcase, 'slate', 'employees'),
                ],
            },
        ],
    },
    {
        key: 'settings', titleKey: 'module_settings', icon: Settings, tone: 'slate',
        tiles: [
            L('/settings/general', 'general_settings', Settings, 'blue', 'settings'),
            L('/settings/users', 'users_roles', Users, 'violet', 'users'),
            L('/settings/branches', 'branches', Building2, 'teal'),
            L('/settings/business-day', 'closing_business_day', CalendarDays, 'amber'),
            L('/settings/holidays', 'holidays', Sun, 'orange', 'settings'),
            L('/settings/notices', 'notices', Megaphone, 'rose', 'settings'),
        ],
        cards: [
            {
                titleKey: 'organization_branches',
                links: [
                    L('/settings/branches', 'branches', Building2, 'teal'),
                    L('/settings/general', 'general_settings', Settings, 'blue', 'settings'),
                ],
            },
            {
                titleKey: 'user_management',
                links: [
                    L('/settings/users', 'users', Users, 'violet', 'users'),
                    L('/settings/roles', 'roles', ShieldCheck, 'cyan', 'roles'),
                    L('/settings/permissions', 'permissions', ShieldCheck, 'slate', 'permissions'),
                ],
            },
            {
                titleKey: 'date_settings',
                links: [
                    L('/settings/business-day', 'closing_business_day', CalendarDays, 'amber'),
                    L('/settings/holidays', 'holidays', Sun, 'orange', 'settings'),
                ],
            },
            {
                titleKey: 'address_settings',
                links: [
                    // Division/district/upazila/union are picked from the
                    // bd-geo-info package inside forms — only the manual
                    // masters (villages, working areas) have pages.
                    L('/settings/geo/villages', 'villages', MapPin, 'pink', 'geo'),
                    L('/settings/geo/working-areas', 'working_areas', MapPin, 'cyan', 'geo'),
                ],
            },
            {
                titleKey: 'samity_notices',
                links: [
                    L('/settings/samities', 'samities', Users, 'violet', 'settings'),
                    L('/settings/notices', 'notices', Megaphone, 'rose', 'settings'),
                ],
            },
            {
                titleKey: 'data_migration',
                links: [
                    L('/settings/member-migrations', 'member_migrations', UploadCloud, 'orange', 'settings'),
                    L('/settings/balance-migrations', 'balance_migrations', UploadCloud, 'teal', 'settings'),
                ],
            },
        ],
    },
];

const visible = (can, link) => !link.can || !!can?.[link.can];

/** Module with permission-filtered tiles/cards (empty cards dropped). */
export function moduleFor(can, key) {
    const mod = MODULES.find((m) => m.key === key);
    if (!mod) return null;
    return {
        ...mod,
        tiles: mod.tiles.filter((l) => visible(can, l)),
        cards: mod.cards
            .map((c) => ({ ...c, links: c.links.filter((l) => visible(can, l)) }))
            .filter((c) => c.links.length > 0),
    };
}

/** All modules permission-filtered; modules with nothing visible drop out. */
export function visibleModules(can) {
    return MODULES
        .map((m) => moduleFor(can, m.key))
        .filter((m) => m.tiles.length > 0 || m.cards.length > 0);
}

/** Every link of a module, deduplicated by href — the Home tile pool. */
export function moduleLinks(mod) {
    const seen = new Map();
    for (const l of [...mod.tiles, ...mod.cards.flatMap((c) => c.links)]) {
        if (!seen.has(l.href)) seen.set(l.href, l);
    }
    return [...seen.values()];
}
