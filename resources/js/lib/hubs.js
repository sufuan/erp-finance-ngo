import {
    ArrowLeftRight, Ban, Banknote, BookOpen, Briefcase, Building2, CalendarDays, CheckCheck, Clock3, Coins, FilePlus2, FileText,
    HandCoins, History, IdCard, Landmark, LayoutGrid, ListChecks, MapPin, Megaphone,
    Package, PiggyBank, Repeat, Scale, ScrollText, Settings, ShieldCheck, Sun, Unlock, UploadCloud,
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
        key: 'collection', titleKey: 'module_collection', icon: HandCoins, tone: 'green',
        tiles: [
            L('/collection/member', 'member_collection', HandCoins, 'green', 'collection'),
            L('/collection/sheet', 'sheet_posting', ListChecks, 'teal', 'collection'),
            L('/collection/simple-sheet', 'simple_sheet_posting', ListChecks, 'cyan', 'collection'),
            L('/collection/reports?report=collection_summary', 'collection_summary_report', ScrollText, 'violet', 'collection'),
            L('/collection/reports?report=dcr_report', 'dcr_report', ScrollText, 'amber', 'collection'),
            L('/collection/balances?report=samity_balance', 'samity_wise_balance_report', Scale, 'blue', 'collection'),
            L('/collection/passbook', 'member_passbook', IdCard, 'pink', 'collection'),
        ],
        cards: [
            {
                titleKey: 'common_collection',
                links: [
                    L('/collection/member', 'member_collection', HandCoins, 'green', 'collection'),
                    L('/collection/member-list', 'member_collection_list', ListChecks, 'blue', 'collection'),
                    L('/collection/sheet', 'sheet_posting', ListChecks, 'teal', 'collection'),
                    L('/collection/samity-list', 'samity_collection_list', ListChecks, 'cyan', 'collection'),
                    L('/collection/other', 'other_collection_receive', Wallet, 'amber', 'collection'),
                ],
            },
            {
                titleKey: 'deposit_investment_collection',
                links: [
                    L('/deposits/receive', 'deposit_receive', HandCoins, 'teal', 'deposits'),
                    L('/deposits/payment', 'deposit_payment', Wallet, 'amber', 'deposits'),
                    L('/loans/receive', 'installment_receive', HandCoins, 'green', 'loans'),
                    L('/collection/simple-sheet', 'simple_sheet_posting', ListChecks, 'cyan', 'collection'),
                ],
            },
            {
                titleKey: 'collection_sheet_section',
                links: [
                    L('/collection/sheets?report=collection_sheet', 'collection_sheet', ScrollText, 'violet', 'collection'),
                    L('/collection/sheets?report=deposit_collection_sheet', 'deposit_collection_sheet', ScrollText, 'teal', 'collection'),
                    L('/collection/sheets?report=loan_collection_sheet', 'loan_collection_sheet', ScrollText, 'amber', 'collection'),
                    L('/collection/sheets?report=loan_collection_sheet_two', 'loan_collection_sheet_two', ScrollText, 'orange', 'collection'),
                ],
            },
            {
                titleKey: 'collection_reports',
                links: [
                    L('/collection/reports?report=collection_details', 'collection_details_report', ScrollText, 'blue', 'collection'),
                    L('/collection/reports?report=collection_summary', 'collection_summary_report', ScrollText, 'violet', 'collection'),
                    L('/collection/reports?report=dcr_report', 'dcr_report', ScrollText, 'amber', 'collection'),
                    L('/collection/reports?report=loan_collection_details', 'loan_collection_details', ScrollText, 'rose', 'collection'),
                    L('/collection/reports?report=deposit_loan_collection_summary', 'deposit_loan_collection_summary', ScrollText, 'cyan', 'collection'),
                ],
            },
            {
                titleKey: 'performance_monitoring_reports',
                links: [
                    L('/collection/reports?report=field_officers_report', 'field_officers_report', ScrollText, 'green', 'collection'),
                    L('/collection/reports?report=periodical_progress', 'periodical_progress_report', ScrollText, 'blue', 'collection'),
                    L('/collection/reports?report=target_vs_achievement', 'target_vs_achievement', ScrollText, 'orange', 'collection'),
                    L('/collection/reports?report=collection_topsheet', 'collection_topsheet', ScrollText, 'violet', 'collection'),
                ],
            },
            {
                titleKey: 'member_services',
                links: [
                    L('/collection/passbook-issue', 'passbook_issue', IdCard, 'pink', 'collection'),
                    L('/collection/cheque-issue', 'cheque_book_issue', IdCard, 'cyan', 'collection'),
                ],
            },
            {
                titleKey: 'balance_reports',
                links: [
                    L('/collection/passbook', 'member_passbook', IdCard, 'pink', 'collection'),
                    L('/collection/balances?report=member_balance', 'member_balance_report', Scale, 'blue', 'collection'),
                    L('/collection/balances?report=samity_balance', 'samity_wise_balance_report', Scale, 'teal', 'collection'),
                    L('/collection/balances?report=officer_balance', 'field_officer_balance_outstanding', Scale, 'amber', 'collection'),
                ],
            },
            {
                titleKey: 'branch_summary_report',
                links: [
                    L('/collection/reports?report=monthly_saving_progress', 'monthly_saving_progress', ScrollText, 'teal', 'collection'),
                    L('/collection/reports?report=monthly_loan_progress', 'monthly_loan_progress', ScrollText, 'amber', 'collection'),
                    L('/collection/reports?report=statements_summary', 'collection_statements_summary', ScrollText, 'blue', 'collection'),
                    L('/collection/reports?report=mis_topsheet', 'mis_topsheet', ScrollText, 'violet', 'collection'),
                    L('/collection/reports?report=mis_progress', 'mis_progress', ScrollText, 'cyan', 'collection'),
                ],
            },
            {
                titleKey: 'pomis_reports',
                links: [
                    L('/collection/reports?report=pomis_1', 'pomis_1', ScrollText, 'rose', 'collection'),
                    L('/collection/reports?report=pomis_2', 'pomis_2', ScrollText, 'orange', 'collection'),
                    L('/collection/reports?report=pomis_3', 'pomis_3', ScrollText, 'amber', 'collection'),
                    L('/collection/reports?report=pomis_4', 'pomis_4', ScrollText, 'slate', 'collection'),
                ],
            },
        ],
    },
    {
        key: 'accounts', titleKey: 'module_accounts', icon: BookOpen, tone: 'cyan',
        tiles: [
            L('/accounts/transaction', 'acc_account_transaction', HandCoins, 'teal', 'accounts'),
            L('/accounts/ledger-transaction', 'acc_ledger_transaction', ArrowLeftRight, 'blue', 'accounts'),
            L('/accounts/reports?report=cash_book&run=1', 'acc_cash_book', BookOpen, 'green', 'accounts'),
            L('/accounts/reports?report=receipt_payment_2&run=1', 'acc_receipt_payment_2', ScrollText, 'violet', 'accounts'),
            L('/accounts/statements?statement=income_expenses_2&run=1', 'acc_income_expenses_2', Scale, 'amber', 'accounts'),
            L('/accounts/transfer-request', 'acc_fund_transfer_request', Banknote, 'orange', 'accounts'),
            L('/accounts/reports?report=trial_balance&run=1', 'acc_trial_balance', Scale, 'slate', 'accounts'),
            L('/accounts/statements?statement=balance_sheet&run=1', 'acc_balance_sheet', Landmark, 'cyan', 'accounts'),
        ],
        cards: [
            {
                titleKey: 'acc_financial_transaction',
                links: [
                    L('/accounts/transaction', 'acc_account_transaction', HandCoins, 'teal', 'accounts'),
                    L('/accounts/ledger-transaction', 'acc_ledger_transaction', ArrowLeftRight, 'blue', 'accounts'),
                    L('/accounts/journal', 'acc_journal_entry', FileText, 'violet', 'accounts'),
                    L('/accounts/contra', 'acc_contra_voucher', ArrowLeftRight, 'amber', 'accounts'),
                    L('/accounts/vouchers', 'acc_voucher_list', ListChecks, 'slate', 'accounts'),
                ],
            },
            {
                titleKey: 'acc_approval_cancel',
                links: [
                    L('/accounts/cancel', 'acc_transaction_cancel', XCircle, 'rose', 'accounts'),
                    L('/accounts/authorization', 'acc_authorization_list', ListChecks, 'blue', 'accounts'),
                    L('/accounts/authorization?mode=approval', 'acc_financial_transaction_approval', CheckCheck, 'green', 'accounts'),
                    L('/accounts/recurring', 'acc_recurring_setup', Repeat, 'cyan', 'accounts'),
                    L('/accounts/vouchers?preset=backdate', 'acc_back_date_list', History, 'orange', 'accounts'),
                    L('/accounts/vouchers?preset=cancelled', 'acc_transaction_cancel_list', Ban, 'rose', 'accounts'),
                ],
            },
            {
                titleKey: 'acc_account_management',
                links: [
                    L('/accounts/ledgers', 'acc_new_account_opening', FilePlus2, 'green', 'accounts'),
                    L('/accounts/ledgers', 'acc_account_list', LayoutGrid, 'blue', 'accounts'),
                    L('/accounts/masters/billing-types', 'acc_billing_type', FileText, 'slate', 'accounts'),
                    L('/accounts/masters/setups', 'acc_receive_payment_setup', Settings, 'cyan', 'accounts'),
                    L('/accounts/group-transaction', 'acc_group_transaction', Coins, 'violet', 'accounts'),
                    L('/accounts/group-transactions', 'acc_group_transaction_list', ListChecks, 'amber', 'accounts'),
                ],
            },
            {
                titleKey: 'acc_inter_branch',
                links: [
                    L('/accounts/transfer-request', 'acc_fund_transfer_request', Banknote, 'orange', 'accounts'),
                    L('/accounts/transfer-requests', 'acc_transfer_request_list', ListChecks, 'blue', 'accounts'),
                    L('/accounts/transfer-request?kind=Product', 'acc_product_sending_request', Package, 'teal', 'accounts'),
                    L('/accounts/transfer-requests?view=receive', 'acc_product_receive_approval', CheckCheck, 'green', 'accounts'),
                    L('/accounts/branch-balances', 'acc_branch_wise_balance', Scale, 'violet', 'accounts'),
                    L('/accounts/branch-statement', 'acc_branch_transaction_statement', ScrollText, 'slate', 'accounts'),
                ],
            },
            {
                titleKey: 'acc_cash_management',
                links: [
                    L('/accounts/cash', 'acc_cash_transfer_request', HandCoins, 'teal', 'accounts'),
                    L('/accounts/cash', 'acc_cash_receive_payment', Wallet, 'amber', 'accounts'),
                    L('/accounts/cashier-balances', 'acc_cashier_balance_report', Scale, 'blue', 'accounts'),
                    L('/accounts/cashier-details', 'acc_cashier_transaction_details', ScrollText, 'cyan', 'accounts'),
                ],
            },
            {
                titleKey: 'acc_account_report',
                links: [
                    L('/accounts/reports?report=account_balance', 'acc_account_balance', Scale, 'blue', 'accounts'),
                    L('/accounts/reports?report=account_receipt_payment', 'acc_account_receipt_payment', ScrollText, 'teal', 'accounts'),
                    L('/accounts/reports?report=account_statement', 'acc_account_statement', FileText, 'violet', 'accounts'),
                    L('/accounts/reports?report=customer_statement', 'acc_customer_statement', Users, 'pink', 'accounts'),
                ],
            },
            {
                titleKey: 'acc_ledger_reports',
                links: [
                    L('/accounts/reports?report=cash_book', 'acc_cash_book', BookOpen, 'green', 'accounts'),
                    L('/accounts/reports?report=daily_cash_book', 'acc_daily_cash_book', BookOpen, 'teal', 'accounts'),
                    L('/accounts/cashier-details', 'acc_cash_transaction_details', ScrollText, 'cyan', 'accounts'),
                    L('/accounts/reports?report=ledger_statement', 'acc_ledger_statement', FileText, 'blue', 'accounts'),
                    L('/accounts/reports?report=receipt_payment', 'acc_receipt_payment', ScrollText, 'amber', 'accounts'),
                    L('/accounts/reports?report=receipt_payment_2', 'acc_receipt_payment_2', ScrollText, 'violet', 'accounts'),
                    L('/accounts/reports?report=income_expense_topsheet', 'acc_income_expense_topsheet', Scale, 'orange', 'accounts'),
                ],
            },
            {
                titleKey: 'acc_statements_analysis',
                links: [
                    L('/accounts/statements?statement=balance_sheet', 'acc_balance_sheet', Landmark, 'cyan', 'accounts'),
                    L('/accounts/statements?statement=balance_sheet_2', 'acc_balance_sheet_2', Landmark, 'blue', 'accounts'),
                    L('/accounts/statements?statement=income_expenses', 'acc_income_expenses', Scale, 'green', 'accounts'),
                    L('/accounts/statements?statement=income_expenses_2', 'acc_income_expenses_2', Scale, 'amber', 'accounts'),
                    L('/accounts/statements?statement=profit_loss', 'acc_profit_loss', ScrollText, 'rose', 'accounts'),
                    L('/accounts/reports?report=trial_balance', 'acc_trial_balance', Scale, 'slate', 'accounts'),
                    L('/accounts/reports?report=trial_balance_2', 'acc_trial_balance_2', Scale, 'violet', 'accounts'),
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
