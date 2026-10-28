/**
 * DOM harness scenarios (run by tests/dom/run.mjs; `npm run test:dom`).
 *
 * Proves the global submit-loader contract against the REAL components:
 * every submission shows a spinner + disabled state for the whole flight
 * and restores afterwards — on success, on validation errors, and on
 * network failures — plus mount checks for representative pages under
 * the real layouts and the withI18n wrapper (EN→BN in one render pass).
 */
import React, { act } from 'react';
import { createRoot } from 'react-dom/client';

import en from '../../lang/en.json';
import bn from '../../lang/bn.json';
import { __setPage, __finishVisit, __pendingVisit, __state } from './inertia-stub.jsx';
import { initI18n } from '../../resources/js/lib/i18n';
import { withI18n } from '../../resources/js/lib/withI18n.jsx';
import { PrimaryButton, ActionButton } from '../../resources/js/Components/ui.jsx';
import ExcelImport from '../../resources/js/Components/ExcelImport.jsx';
import SamitySchedulePicker, { scheduleTokenOf, scheduleLabel } from '../../resources/js/Components/SamitySchedulePicker.jsx';
import Login from '../../resources/js/Pages/Auth/Login.jsx';
import Home from '../../resources/js/Pages/Home.jsx';
import Dashboard from '../../resources/js/Pages/Dashboard.jsx';
import BusinessDay from '../../resources/js/Pages/Settings/BusinessDay/Index.jsx';
import MemberClosing from '../../resources/js/Pages/Members/Closing.jsx';
import MemberMigrations from '../../resources/js/Pages/Settings/MemberMigrations.jsx';
import SamitiesIndex from '../../resources/js/Pages/Settings/Samities/Index.jsx';
import SamityChanges from '../../resources/js/Pages/Settings/Samities/Changes.jsx';
import GeneralSettings from '../../resources/js/Pages/Settings/General/Index.jsx';
import ProposalCreate from '../../resources/js/Pages/Loans/ProposalCreate.jsx';
import LoanOpening from '../../resources/js/Pages/Loans/Opening.jsx';
import DepositOpening from '../../resources/js/Pages/Deposits/Opening.jsx';
import DepositReports from '../../resources/js/Pages/Reports/DepositReports.jsx';
import LoanReports from '../../resources/js/Pages/Reports/LoanReports.jsx';
import RegistersIndex from '../../resources/js/Pages/Settings/Registers/Index.jsx';
import SheetPosting from '../../resources/js/Pages/Collections/SheetPosting.jsx';
import CollectionReports from '../../resources/js/Pages/Collections/Reports.jsx';
import PassbookIssue from '../../resources/js/Pages/Collections/PassbookIssue.jsx';
import ChequeBookIssue from '../../resources/js/Pages/Collections/ChequeBookIssue.jsx';
import DepositShare from '../../resources/js/Pages/Deposits/Share.jsx';

const failures = [];
let passCount = 0;
function check(name, cond) {
    if (cond) { passCount += 1; console.log(`PASS ${name}`); }
    else { failures.push(name); console.log(`FAIL ${name}`); }
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function mount(node) {
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    act(() => root.render(node));
    return { root, container };
}
async function rerender(m, node) {
    await act(async () => { m.root.render(node); });
}
async function unmount(m) {
    await act(async () => { m.root.unmount(); });
    m.container.remove();
}
const spinning = (el) => !!el?.querySelector('svg.animate-spin');

function setValue(el, value, event = 'input') {
    const proto = el instanceof window.HTMLTextAreaElement ? window.HTMLTextAreaElement.prototype
        : el instanceof window.HTMLSelectElement ? window.HTMLSelectElement.prototype
            : window.HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, value);
    el.dispatchEvent(new window.Event(event, { bubbles: true }));
}

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
    .map((d) => ({ id: d, label_key: `day_${d.toLowerCase()}` }));

const baseProps = (over = {}) => ({
    locale: 'en',
    translations: en,
    translations_version: 'harness-en',
    appName: 'TPDA e-Sheba',
    orgName: 'Talgachi Poor Development Association',
    businessDate: '21-Oct-2026',
    branches: [
        { id: 1, code: '101', name: 'Head Office' },
        { id: 2, code: '102', name: 'Talgachi' },
    ],
    activeBranch: { id: 2, code: '102', name: 'Talgachi' },
    notice: null,
    flash: {},
    errors: {},
    auth: {
        user: { id: 1, name: 'Admin', username: 'admin' },
        can: { members: true, deposits: true, loans: true, settings: true, reports: true, hrm: true, accounts: true },
    },
    ...over,
});

async function scenarioPrimaryButton() {
    let m = mount(<PrimaryButton loading>Save</PrimaryButton>);
    const b = m.container.querySelector('button');
    check('PrimaryButton loading: disabled + spinner', b.disabled && spinning(b));
    await unmount(m);
    m = mount(<PrimaryButton>Save</PrimaryButton>);
    const b2 = m.container.querySelector('button');
    check('PrimaryButton idle: enabled, no spinner', !b2.disabled && !spinning(b2));
    await unmount(m);
}

async function scenarioActionButton() {
    let fires = 0;
    let captured = null;
    const m = mount(
        <ActionButton fire={(cb) => { fires += 1; captured = cb; }}>Approve</ActionButton>,
    );
    const btn = () => m.container.querySelector('button');
    await act(async () => { btn().click(); });
    check('ActionButton: fires once, disabled + spinner in flight', fires === 1 && btn().disabled && spinning(btn()));
    await act(async () => { btn().click(); btn().click(); });
    check('ActionButton: double-click while pending does NOT re-fire', fires === 1);
    await act(async () => { captured.onFinish(); });
    check('ActionButton: restores after onFinish', !btn().disabled && !spinning(btn()));
    // Two clicks in the SAME tick (state updates not yet flushed): the
    // ref guard must still let exactly one fire through. The finish of
    // an errored visit is the same onFinish callback, so restoring via
    // onFinish also covers the validation/server-error return path.
    await act(async () => { btn().click(); btn().click(); });
    check('ActionButton: same-tick double-click fires exactly once', fires === 2);
    await act(async () => { captured.onFinish(); });
    check('ActionButton: restores after error-path finish too', !btn().disabled && !spinning(btn()));
    await unmount(m);
}

async function scenarioLoginUseForm() {
    __setPage(baseProps());
    const Wrapped = withI18n(Login);
    const m = mount(<Wrapped />);
    const form = m.container.querySelector('form');
    const btn = () => form.querySelector('button[type="submit"]');
    check('Login mounts, submit enabled', !!form && !btn().disabled);
    await act(async () => { form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })); });
    check('Login submit: visit pending, button disabled + spinner', !!__pendingVisit() && btn().disabled && spinning(btn()));
    await act(async () => { __finishVisit({ errors: { username: 'These credentials do not match.' } }); });
    check('Login validation-error return: button restored, error shown',
        !btn().disabled && !spinning(btn()) && m.container.textContent.includes('These credentials do not match.'));
    await act(async () => { form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })); });
    check('Login resubmit: disabled again', btn().disabled);
    await act(async () => { __finishVisit({}); });
    check('Login success return: button restored', !btn().disabled && !spinning(btn()));
    await unmount(m);
}

async function scenarioBusinessDayRawRouter() {
    __setPage(baseProps());
    window.confirm = () => true;
    const m = mount(<BusinessDay branch={{ id: 2, code: '102', name: 'Talgachi' }} currentDate="2026-10-21" nextDate="2026-10-22" autoClose={false} />);
    const btn = [...m.container.querySelectorAll('button')].find((b) => b.textContent.includes(en.start_closing));
    check('BusinessDay mounts with Start Closing enabled', !!btn && !btn.disabled);
    await act(async () => { btn.click(); });
    check('BusinessDay close: disabled + spinner in flight', btn.disabled && spinning(btn) && !!__pendingVisit());
    await act(async () => { __finishVisit({ errors: { date: 'nope' } }); });
    check('BusinessDay close: restored after error finish (onFinish wired)', !btn.disabled && !spinning(btn));
    await act(async () => { btn.click(); });
    await act(async () => { __finishVisit({}); });
    check('BusinessDay close: restored after success finish', !btn.disabled);
    await unmount(m);
}

async function scenarioMemberClosing() {
    __setPage(baseProps());
    const closing = {
        id: 5, member_id: 7, member: { member_no: 'M-0001', name: 'Amina Khatun' },
        closing_date: '2026-10-20', closing_reason: 'Voluntary', share_disposition: 'Refunded',
        share_amount_at_closing: '500', closed_by_name: 'Admin', closed_at: '2026-10-20 10:00',
        cancelled_by_name: null, cancelled_at: null,
    };
    const m = mount(<MemberClosing members={[]} reasons={['Voluntary', 'Migration']} businessDate="21-Oct-2026" closings={[closing]} />);
    const searchBtn = [...m.container.querySelectorAll('button')].find((b) => b.textContent.trim() === en.search);
    check('MemberClosing mounts, search enabled', !!searchBtn && !searchBtn.disabled);
    await act(async () => { searchBtn.click(); });
    check('MemberClosing search (router.get): disabled + spinner in flight', searchBtn.disabled && spinning(searchBtn));
    await act(async () => { __finishVisit({}); });
    check('MemberClosing search: restored after finish', !searchBtn.disabled);
    const cancelBtn = [...m.container.querySelectorAll('button')].find((b) => b.textContent.trim() === en.cancel);
    const before = __state.visits.length;
    await act(async () => { cancelBtn.click(); });
    await act(async () => { cancelBtn.click(); });
    const fired = __state.visits.slice(before).filter((v) => v.url.includes('/members/closing/5/cancel'));
    check('MemberClosing cancel (ActionButton): single fire, disabled in flight', fired.length === 1 && cancelBtn.disabled && spinning(cancelBtn));
    await act(async () => { __finishVisit({}); });
    check('MemberClosing cancel: restored after finish', !cancelBtn.disabled);
    await unmount(m);
}

const EXCEL_COLS = [
    { key: 'name', label: 'Member Name', required: true, input: 'text' },
    { key: 'mobile', label: 'Mobile', required: true, input: 'text' },
];
const EXCEL_ENDPOINTS = { preview: '/x/preview', validate: '/x/validate', confirm: '/x/confirm' };
let fetchImpl = () => Promise.reject(new Error('no fetch stub'));
globalThis.fetch = (...args) => fetchImpl(...args);
const okJson = (payload) => Promise.resolve({ ok: true, status: 200, json: async () => payload });

async function excelMount() {
    initI18n({ locale: 'en', translations: en, translations_version: 'harness-excel' });
    return mount(<ExcelImport columns={EXCEL_COLS} endpoints={EXCEL_ENDPOINTS} context={{}} />);
}
async function excelAddValidRow(m) {
    const addBtn = [...m.container.querySelectorAll('button')].find((b) => b.textContent.includes(en.excel_add_row));
    await act(async () => { addBtn.click(); });
    const inputs = m.container.querySelectorAll('tbody input');
    await act(async () => { setValue(inputs[0], 'Amina Khatun'); });
    await act(async () => { setValue(inputs[1], '01712345678'); });
}

async function scenarioExcelImport() {
    // (1) validation network failure: busy must clear AND the grid must
    // stay stale (Confirm stays disabled) — regression for the stuck
    // 'validating' state and the stale-flag being cleared on failure.
    let m = await excelMount();
    fetchImpl = (url) => (url.includes('/validate')
        ? okJson({ rows: [{ cells: { name: 'Amina Khatun', mobile: '01712345678' }, errors: {} }], error_count: 0 })
        : Promise.reject(new Error('unused')));
    await excelAddValidRow(m);
    await act(async () => { await sleep(800); });
    const confirmBtn = () => [...m.container.querySelectorAll('button')].find((b) => b.textContent.includes(en.confirm_import));
    check('ExcelImport: confirm enabled once grid validates clean', !!confirmBtn() && !confirmBtn().disabled);
    fetchImpl = () => Promise.reject(new Error('network down'));
    const firstInput = m.container.querySelector('tbody input');
    await act(async () => { setValue(firstInput, 'Amina Khatun Edited'); });
    await act(async () => { await sleep(800); });
    check('ExcelImport: failed re-validation clears busy (no stuck validating state)',
        !m.container.textContent.includes(en.excel_validating));
    check('ExcelImport: failed re-validation keeps grid stale (confirm disabled)', confirmBtn().disabled);
    check('ExcelImport: network failure surfaces the failed message',
        m.container.textContent.includes(en.import_failed));
    await unmount(m);

    // (2) confirm network failure: the importing spinner must clear and
    // the (still valid) grid must allow a retry.
    m = await excelMount();
    fetchImpl = (url) => (url.includes('/validate')
        ? okJson({ rows: [{ cells: { name: 'Amina Khatun', mobile: '01712345678' }, errors: {} }], error_count: 0 })
        : Promise.reject(new Error('network down')));
    await excelAddValidRow(m);
    await act(async () => { await sleep(800); });
    const btn2 = [...m.container.querySelectorAll('button')].find((b) => b.textContent.includes(en.confirm_import));
    await act(async () => { btn2.click(); });
    await act(async () => { await sleep(50); });
    const btn2After = [...m.container.querySelectorAll('button')].find((b) => b.textContent.includes(en.confirm_import));
    check('ExcelImport: failed confirm clears the importing state (retry possible)',
        !!btn2After && !btn2After.disabled && !spinning(btn2After));
    await unmount(m);

    // (3) upload network failure: the parsing state must clear.
    m = await excelMount();
    fetchImpl = () => Promise.reject(new Error('network down'));
    const fileInput = m.container.querySelector('input[type="file"]');
    Object.defineProperty(fileInput, 'files', { value: [new window.File(['x'], 'members.xlsx')], configurable: true });
    await act(async () => { fileInput.dispatchEvent(new window.Event('change', { bubbles: true })); });
    await act(async () => { await sleep(50); });
    check('ExcelImport: failed upload clears parsing state + shows import failure',
        !m.container.textContent.includes(en.excel_validating) && m.container.textContent.includes(en.import_failed));
    await unmount(m);
}

async function scenarioSchedulePicker() {
    initI18n({ locale: 'en', translations: en, translations_version: 'harness-picker' });
    check('scheduleTokenOf: day only → weekly token', scheduleTokenOf({ samity_day: 'Wednesday', date_of_month: null }) === 'Wednesday');
    check('scheduleTokenOf: date only → monthly token', scheduleTokenOf({ samity_day: null, date_of_month: 15 }) === 'monthly:15');
    check('scheduleTokenOf: legacy both set → date wins', scheduleTokenOf({ samity_day: 'Wednesday', date_of_month: 15 }) === 'monthly:15');
    check('scheduleTokenOf: date 0 → last day', scheduleTokenOf({ samity_day: null, date_of_month: 0 }) === 'monthly:last');
    check('scheduleLabel: last day', scheduleLabel('monthly:last') === `${en.monthly_prefix} — ${en.last_day_of_month}`);
    check('scheduleLabel: weekday', scheduleLabel('Wednesday') === en.day_wednesday);
    let m = mount(<SamitySchedulePicker days={DAYS} frequency="weekly" day="Wednesday" dateOfMonth="" onFrequency={() => {}} onDay={() => {}} onDate={() => {}} />);
    const wed = [...m.container.querySelectorAll('button')].find((b) => b.textContent === 'Wednesday');
    check('Picker weekly prefill: Wednesday chip pressed', wed?.getAttribute('aria-pressed') === 'true');
    await unmount(m);
    m = mount(<SamitySchedulePicker days={DAYS} frequency="monthly" day="" dateOfMonth="0" onFrequency={() => {}} onDay={() => {}} onDate={() => {}} />);
    const last = [...m.container.querySelectorAll('button')].find((b) => b.textContent === en.last_day_of_month);
    check('Picker monthly prefill: Last day chip pressed', last?.getAttribute('aria-pressed') === 'true');
    await unmount(m);
}

async function mountBilingual(name, Page, props, { enText, bnText, probe }) {
    const read = probe ?? ((c) => c.textContent);
    __setPage(baseProps());
    const Wrapped = withI18n(Page);
    const m = mount(<Wrapped {...props} />);
    check(`${name}: mounts in EN`, read(m.container).includes(enText));
    __setPage(baseProps({ locale: 'bn', translations: bn, translations_version: 'harness-bn' }));
    await rerender(m, <Wrapped {...props} />);
    check(`${name}: BN switch — content AND layout agree (no lag)`,
        read(m.container).includes(bnText) && !read(m.container).includes(enText));
    await unmount(m);
}

async function scenarioPageMounts() {
    await mountBilingual('Home', Home, {}, {
        enText: en.search_modules_placeholder,
        bnText: bn.search_modules_placeholder,
        probe: (c) => c.querySelector('input')?.getAttribute('placeholder') ?? '',
    });

    await mountBilingual('MemberMigrations', MemberMigrations, {
        samities: [{ id: 1, label: 'S-001 — Talgachi Samity' }],
        batches: [],
        preview: null,
        excelColumns: EXCEL_COLS,
    }, { enText: en.member_migrations, bnText: bn.member_migrations });

    // Samities Index + legacy edit prefill (both day and date set → the
    // monthly date must win in the form's picker).
    __setPage(baseProps());
    const samityRow = {
        id: 9, samity_code: 'S-LEG', name: 'Legacy Samity', branch: { code: '102' },
        samity_day: 'Wednesday', date_of_month: 15, meeting_frequency: null,
        field_officer: { name: 'Field Officer' }, member_count: 3, status: 'Active',
        branch_id: 2, center_no: '1', center_type: 'Permanent', working_area_id: '',
        field_officer_id: '', manager_id: '', group_leader_id: '', address: '',
        latitude: '', longitude: '', formation_date: '2020-01-01',
    };
    const options = {
        branches: [{ id: 2, name: '102 — Talgachi' }],
        employees: [{ id: 1, name: 'Officer One' }],
        workingAreas: [],
        centerTypes: [{ id: 'Permanent', name: 'Permanent' }],
        days: DAYS,
    };
    const WrappedSamities = withI18n(SamitiesIndex);
    let m = mount(<WrappedSamities samities={[samityRow]} options={options} businessDate="21-Oct-2026" activeBranchId={2} />);
    check('Samities Index: mounts, legacy row shows monthly label', m.container.textContent.includes(`${en.monthly_prefix} — 15`));
    const editBtn = [...m.container.querySelectorAll('button')].find((b) => b.textContent.trim() === en.edit);
    await act(async () => { editBtn.click(); });
    const dateChip = [...m.container.querySelectorAll('button')].find((b) => b.textContent === '15');
    check('Samities Index edit: legacy both-set row prefills Monthly date 15',
        dateChip?.getAttribute('aria-pressed') === 'true');
    await unmount(m);

    await mountBilingual('SamityChanges', SamityChanges, {
        type: 'day', label: 'samity_day_change', field: 'samity_day',
        samities: [{ id: 1, samity_code: 'S1', name: 'Talgachi Samity', samity_day: 'Wednesday', date_of_month: null, meeting_frequency: 'weekly', field_officer_id: '', manager_id: '' }],
        history: [], options: { employees: [], days: DAYS }, businessDate: '21-Oct-2026', selectedSamityId: '',
    }, { enText: en.samity_day_change, bnText: bn.samity_day_change });

    // General Settings under poisoned (double-encoded) stored values:
    // the page-level guards must keep every tab mountable.
    __setPage(baseProps());
    const poisoned = {
        'app.name': 'TPDA e-Sheba', 'app.language': 'en', 'app.currency': 'BDT', 'app.date_format': 'd-M-Y',
        'org.name': 'TPDA', 'org.address': 'Talgachi', 'org.phone': '01', 'org.email': 'a@b.c', 'org.registration_no': 'x',
        'print.header_lines': '["Line 1","Line 2"]', 'print.footer_note': 'note', 'print.paper': 'a4',
        'loan.interest_rate': '15', 'loan.allocation_order': 'penalty,profit,principal',
        'sms.provider': 'none', 'business.open_time': '09:00', 'business.close_time': '17:00',
        'member.id_prefix': 'M', 'deposit.min_balance': '0', 'security.session_timeout': '120',
    };
    const profile = {
        name: 'TPDA', short_name: 'TPDA', tagline: '', email: 'a@b.c', phone: '01', website: '',
        address: 'Talgachi', registration_no: 'x', established_year: '2000', logo_path: null, logo_url: null,
    };
    const WrappedSettings = withI18n(GeneralSettings);
    m = mount(<WrappedSettings profile={profile} settings={poisoned} />);
    check('General Settings: mounts under poisoned settings', m.container.textContent.includes(en.organization));
    const loanTab = [...m.container.querySelectorAll('button')].find((b) => b.textContent.includes(en.loan_and_profit));
    await act(async () => { loanTab.click(); });
    const allocRows = [...m.container.querySelectorAll('li')].filter((li) => li.textContent.includes(en.penalty) || li.textContent.includes(en.principal));
    check('General Settings: poisoned allocation string still renders the drag rows', allocRows.length >= 2);
    await unmount(m);
}

// ---- Live-CRUD-pass regressions (2026-10-27) ----
// The member lookup on the loan/deposit forms round-trips with
// preserveState, so useForm keeps its first-mount values: mounted with
// member=null the submitted member_id stayed '' and the server rejected
// the post ("The member id field is required." / a silent no-op where
// the page never rendered that error). These scenarios reproduce the
// lookup-then-submit flow and pin the synced payload.
const HARNESS_MEMBER = {
    id: 4, member_no: '102000004', name: 'LIVE TEST Member', mobile: '01710000000',
    present_address: 'Talgachi', samity: '0001 — Talgachi Samity', branch: '102 — Talgachi',
    share_amount: '0.00', savings_balance: '0.00', status: 'Active', photo_url: null,
    nominee: { name: 'Nominee One' },
};
const LOAN_PRODUCTS = [{ id: 1, code: '3001', name_en: 'General Loan', profit_rate: '15.00', rate_method: 'declining', allowed_frequencies: ['D', 'W', 'M'] }];
const FREQUENCIES = [
    { code: 'D', label_en: 'Daily' }, { code: 'W', label_en: 'Weekly' }, { code: 'M', label_en: 'Monthly' },
];
const mainFormOf = (container) => [...container.querySelectorAll('form')]
    .find((f) => !f.querySelector('input[name="member"]') && !f.querySelector('input[name="employee"]'));
const lastPostTo = (url) => __state.visits.filter((v) => v.method === 'post' && v.url.startsWith(url)).pop();

async function scenarioProposalMemberSync() {
    __setPage(baseProps());
    const Wrapped = withI18n(ProposalCreate);
    const props = {
        memberQuery: '102000004', products: LOAN_PRODUCTS, frequencies: FREQUENCIES,
        fdrAccounts: [], canOverride: false, businessDate: '2026-10-26',
    };
    const m = mount(<Wrapped {...props} member={null} />);
    check('ProposalCreate: mounts before member lookup (main form hidden until lookup)',
        !!m.container.querySelector('input[name="member"]') && !mainFormOf(m.container));
    await rerender(m, <Wrapped {...props} member={HARNESS_MEMBER} />);
    check('ProposalCreate: member profile renders after lookup', m.container.textContent.includes('102000004'));
    await act(async () => { mainFormOf(m.container).dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })); });
    check('ProposalCreate submit: member_id synced from lookup (defect 1)', lastPostTo('/loans/proposals')?.data?.member_id === 4);
    await act(async () => { __finishVisit({}); });
    await unmount(m);
}

async function scenarioLoanOpeningMemberSync() {
    __setPage(baseProps());
    const Wrapped = withI18n(LoanOpening);
    const props = {
        mode: 'member', proposal: null, memberQuery: '102000004', employee: null, employeeQuery: '',
        products: LOAN_PRODUCTS, frequencies: FREQUENCIES, feeTypes: [], canOverride: false,
        businessDate: '2026-10-26', productLoans: [],
    };
    const m = mount(<Wrapped {...props} member={null} />);
    await rerender(m, <Wrapped {...props} member={HARNESS_MEMBER} />);
    check('LoanOpening: member profile renders after lookup', m.container.textContent.includes('102000004'));
    await act(async () => { mainFormOf(m.container).dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })); });
    const visit = lastPostTo('/loans/opening');
    check('LoanOpening submit: member_id synced from lookup (defect 4)', visit?.data?.member_id === 4);
    check('LoanOpening submit: blank payment fields post as blanks (optional)', visit?.data?.payment_type === '' && visit?.data?.payment_to_account === '');
    await act(async () => { __finishVisit({}); });
    await unmount(m);
}

async function scenarioDepositOpeningMemberSync() {
    __setPage(baseProps());
    const Wrapped = withI18n(DepositOpening);
    const products = [{ id: 1, code: 'SAVINGS', name_en: 'Savings', default_profit_rate: '5.00', config: null }];
    const props = { products, memberQuery: '102000004', businessDate: '2026-10-26' };
    const m = mount(<Wrapped {...props} member={null} />);
    await rerender(m, <Wrapped {...props} member={HARNESS_MEMBER} />);
    check('DepositOpening: member profile renders after lookup', m.container.textContent.includes('102000004'));
    const form = [...m.container.querySelectorAll('form')].find((f) => f.querySelector('select'));
    await act(async () => { form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })); });
    const visit = lastPostTo('/deposits');
    check('DepositOpening submit: member_id synced from lookup (defect 3)', visit?.data?.member_id === 4);
    check('DepositOpening submit: phone prefilled from member', visit?.data?.phone === '01710000000');
    await act(async () => { __finishVisit({}); });
    await unmount(m);
}

// Same defect class, found 2026-10-28 while recording the Collection
// tour: Passbook Issue mounted with member=null, so after the lookup
// round-trip the issue form still posted member_id '' and the server
// rejected it with "The member id field is required."
async function scenarioPassbookIssueMemberSync() {
    __setPage(baseProps());
    const Wrapped = withI18n(PassbookIssue);
    const props = {
        memberQuery: '102000004', rows: [], nextBookNumber: '1002', businessDate: '2026-10-28',
    };
    const m = mount(<Wrapped {...props} member={null} />);
    check('PassbookIssue: mounts before member lookup (issue form hidden until lookup)',
        !!m.container.querySelector('input[name="member"]') && !mainFormOf(m.container));
    await rerender(m, <Wrapped {...props} member={HARNESS_MEMBER} />);
    check('PassbookIssue: member renders after lookup', m.container.textContent.includes('102000004'));
    await act(async () => { mainFormOf(m.container).dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })); });
    const visit = lastPostTo('/collection/passbook-issue');
    check('PassbookIssue submit: member_id synced from lookup (defect: passbook issue)', visit?.data?.member_id === 4);
    check('PassbookIssue submit: book number posts from the nextBookNumber prop', visit?.data?.book_number === '1002');
    await act(async () => { __finishVisit({}); });
    await unmount(m);
}

// Same defect class as Passbook Issue, flagged by the same review:
// Cheque Book Issue mounted with member=null, so after the lookup
// round-trip the issue form still posted member_id '' and the server
// rejected it with "The member id field is required."
async function scenarioChequeBookIssueMemberSync() {
    __setPage(baseProps());
    const Wrapped = withI18n(ChequeBookIssue);
    const props = {
        memberQuery: '102000004', rows: [], businessDate: '2026-10-28',
    };
    const m = mount(<Wrapped {...props} member={null} />);
    check('ChequeBookIssue: mounts before member lookup (issue form hidden until lookup)',
        !!m.container.querySelector('input[name="member"]') && !mainFormOf(m.container));
    await rerender(m, <Wrapped {...props} member={HARNESS_MEMBER} />);
    check('ChequeBookIssue: member renders after lookup', m.container.textContent.includes('102000004'));
    await act(async () => { mainFormOf(m.container).dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })); });
    const visit = lastPostTo('/collection/cheque-issue');
    check('ChequeBookIssue submit: member_id synced from lookup (defect: cheque book issue)', visit?.data?.member_id === 4);
    await act(async () => { __finishVisit({}); });
    await unmount(m);
}

// Same defect class in Deposits → Share: the page mounted with
// member=null, so after the lookup round-trip the share form still
// posted member_id '' and the server rejected it with
// "The member id field is required."
async function scenarioDepositShareMemberSync() {
    __setPage(baseProps());
    const Wrapped = withI18n(DepositShare);
    const snapshot = {
        name: HARNESS_MEMBER.name, member_no: HARNESS_MEMBER.member_no,
        samity: HARNESS_MEMBER.samity, share_amount: '500.00',
    };
    const props = { memberQuery: '102000004', mode: 'receive', businessDate: '2026-10-28' };
    const m = mount(<Wrapped {...props} member={null} snapshot={null} />);
    check('DepositShare: mounts before member lookup (share form hidden until lookup)',
        !!m.container.querySelector('input[name="member"]') && !mainFormOf(m.container));
    await rerender(m, <Wrapped {...props} member={HARNESS_MEMBER} snapshot={snapshot} />);
    check('DepositShare: member renders after lookup', m.container.textContent.includes('102000004'));
    await act(async () => { mainFormOf(m.container).dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })); });
    const visit = lastPostTo('/deposits/share');
    check('DepositShare submit: member_id synced from lookup (defect: share)', visit?.data?.member_id === 4);
    check('DepositShare submit: mode posts from the page mode', visit?.data?.mode === 'receive');
    await act(async () => { __finishVisit({}); });
    await unmount(m);
}

// Same form, second mount-captured field: `mode`. switchMode re-GETs
// with preserveState, so after looking a member up in Receive mode and
// switching to Payment, the heading/buttons follow the mode prop but
// the submit still posted mode 'receive' — a share payment recorded
// as a receive (wrong direction on a money form).
async function scenarioDepositShareModeSync() {
    __setPage(baseProps());
    const Wrapped = withI18n(DepositShare);
    const snapshot = {
        name: HARNESS_MEMBER.name, member_no: HARNESS_MEMBER.member_no,
        samity: HARNESS_MEMBER.samity, share_amount: '500.00',
    };
    const props = { memberQuery: '102000004', businessDate: '2026-10-28' };
    const m = mount(<Wrapped {...props} member={null} snapshot={null} mode="receive" />);
    // Lookup round-trip: the member arrives, still in Receive mode.
    await rerender(m, <Wrapped {...props} member={HARNESS_MEMBER} snapshot={snapshot} mode="receive" />);
    check('DepositShare mode switch: member renders after lookup', m.container.textContent.includes('102000004'));
    // Mode-switch round-trip (preserveState): only the mode prop changes.
    await rerender(m, <Wrapped {...props} member={HARNESS_MEMBER} snapshot={snapshot} mode="payment" />);
    await act(async () => { mainFormOf(m.container).dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })); });
    const visit = lastPostTo('/deposits/share');
    check('DepositShare submit after mode switch: member_id still synced', visit?.data?.member_id === 4);
    check('DepositShare submit after mode switch: mode synced to payment (defect: share mode)', visit?.data?.mode === 'payment');
    await act(async () => { __finishVisit({}); });
    await unmount(m);
}

async function scenarioReportPagesMount() {
    // Defect 2: both report pages crashed at render with
    // "searching is not defined" (the useState was nested inside the
    // values initializer). Mounting with realistic props must not throw.
    __setPage(baseProps());
    const shared = {
        reports: [{ key: 'register', title: 'reports', filters: ['samity_id', 'from_date', 'to_date'] }],
        reportKey: 'register',
        activeFilters: ['samity_id', 'from_date', 'to_date'],
        filters: { samity_id: '', from_date: '2026-10-01', to_date: '2026-10-26' },
        columns: [], rows: [], totalRow: null, ran: false,
        samities: [{ id: 1, samity_code: '0001', name: 'Talgachi Samity' }],
        employees: [{ id: 1, employee_code: '000001', name: 'Officer One' }],
        products: [{ id: 1, code: 'SAVINGS', name_en: 'Savings' }],
    };
    const WrappedDeposits = withI18n(DepositReports);
    let m = mount(<WrappedDeposits {...shared} accountStatuses={['all', 'active', 'closed']} />);
    check('DepositReports: mounts without ReferenceError (defect 2)', m.container.textContent.includes(en.reports));
    await unmount(m);
    const WrappedLoans = withI18n(LoanReports);
    m = mount(<WrappedLoans {...shared} frequencies={FREQUENCIES} loanTypes={[]} loanStatuses={[]} />);
    check('LoanReports: mounts without ReferenceError (defect 2)', m.container.textContent.includes(en.reports));
    await unmount(m);
}

async function scenarioRegistersDateFormat() {
    // Defect 8: the employees list printed the raw ISO datetime for
    // date fields; it must render the app's d-M-Y display format.
    __setPage(baseProps());
    const Wrapped = withI18n(RegistersIndex);
    const fields = [
        { key: 'name', type: 'text', required: true, section: 'personal', label: 'name', options: null },
        { key: 'joining_date', type: 'date', required: true, section: 'personal', label: 'joining_date', options: null },
        { key: 'status', type: 'select', section: 'personal', label: 'status', options: [{ id: 'Active', name: 'Active' }] },
    ];
    const items = [{ id: 1, name: 'Officer One', joining_date: '2015-03-01T00:00:00.000000Z', status: 'Active' }];
    const m = mount(<Wrapped entity="employees" label="employees" items={items} fields={fields} search="" />);
    check('Registers: joining date renders as 01-Mar-2015 (defect 8)', m.container.textContent.includes('01-Mar-2015'));
    check('Registers: no raw ISO datetime leaks into the list', !m.container.textContent.includes('2015-03-01T00:00'));
    await unmount(m);
}

async function scenarioRegistersTypeManager() {
    // Live retest (2026-10-27): the employees list printed the raw
    // employee_type_id ("1") and manager_id ("3") in the Employee Type
    // and Manager columns; they must show the type NAME and the
    // manager's NAME (dash when the employee has no manager).
    __setPage(baseProps());
    const Wrapped = withI18n(RegistersIndex);
    const fields = [
        { key: 'name', type: 'text', required: true, section: 'personal', label: 'name', options: null },
        { key: 'employee_type_id', type: 'select', section: 'personal', label: 'employee_type', options: [] },
        { key: 'manager_id', type: 'select', section: 'personal', label: 'manager', options: [] },
    ];
    const items = [
        {
            id: 10, name: 'Officer With Manager', employee_type_id: 1, manager_id: 3,
            employee_type: { id: 1, name: 'Permanent' },
            manager: { id: 3, employee_code: '000003', name: 'Karim Boss' },
        },
        {
            id: 11, name: 'Officer No Manager', employee_type_id: 1, manager_id: null,
            employee_type: { id: 1, name: 'Permanent' },
            manager: null,
        },
    ];
    const m = mount(<Wrapped entity="employees" label="employees" items={items} fields={fields} search="" />);
    const cells = [...m.container.querySelectorAll('tbody tr')].map((tr) => [...tr.querySelectorAll('td')].map((td) => td.textContent));
    // Table columns: photo, name, employee_type_id, manager_id, actions.
    check('Registers: employee type cell shows the type name, not the raw id', cells[0]?.[2] === 'Permanent');
    check('Registers: manager cell shows the manager name, not the raw id', cells[0]?.[3] === '000003 — Karim Boss');
    check('Registers: employee with no manager shows a dash in the manager cell', cells[1]?.[3] === '—');
    await unmount(m);
}

async function scenarioCollectionSheetPosting() {
    // Collection module signature page: the grid prefills receivables
    // and the Summary card (Total Receivable / Receive / Dues) must
    // update live as amounts are edited.
    __setPage(baseProps());
    const grid = [{
        member_id: 7, member_no: '102000007', member_name: 'Sheet Member',
        accounts: [
            { account_type: 'deposit', account_id: 11, label: '102000011', product_code: 'SAVINGS', product_label: 'Savings', balance: '0.00', receivable: '500.00', amount: '500.00' },
            { account_type: 'loan', account_id: 21, label: '102000021', product_code: null, product_label: 'Loan', balance: '12000.00', receivable: '1083.10', amount: '1083.10' },
            { account_type: 'share', account_id: 7, label: '102000007', product_code: null, product_label: 'Share', balance: '500.00', receivable: '0.00', amount: '0.00' },
        ],
    }];
    const props = {
        samities: [{ id: 3, code: '0001', name: 'Talgachi Samity' }],
        samityId: 3, grid, businessDate: '2026-10-27',
    };
    const Wrapped = withI18n(SheetPosting);
    const m = mount(<Wrapped {...props} />);
    const text = () => m.container.textContent;
    check('SheetPosting: mounts with member row', text().includes('Sheet Member'));
    check('SheetPosting: summary shows total receivable 1583.10', text().includes('1583.10'));
    check('SheetPosting: summary dues start at 0.00', text().includes(en.total_dues));
    const amountInputs = [...m.container.querySelectorAll('input[type="number"]')];
    check('SheetPosting: one amount input per account', amountInputs.length === 3);
    await act(async () => { setValue(amountInputs[0], '200'); });
    check('SheetPosting: editing deposit to 200 makes dues 300.00', text().includes('300.00'));
    check('SheetPosting: total receive drops to 1283.10', text().includes('1283.10'));
    await unmount(m);

    // BN render: heading switches with the locale.
    __setPage(baseProps({ locale: 'bn', translations: bn, translations_version: 'harness-bn' }));
    const m2 = mount(<Wrapped {...props} />);
    check('SheetPosting: BN heading renders', m2.container.textContent.includes(bn.sheet_posting));
    await unmount(m2);
    __setPage(baseProps());
}

async function scenarioCollectionReportsMount() {
    __setPage(baseProps());
    const Wrapped = withI18n(CollectionReports);
    const m = mount(<Wrapped
        group="reports"
        reportKey="collection_details"
        reports={[{ key: 'collection_details', title: 'collection_details_report', group: 'reports', filters: ['from_date', 'to_date'] }]}
        activeFilters={['from_date', 'to_date']}
        filters={{ from_date: '', to_date: '' }}
        columns={[]} rows={[]} totalRow={null} ran={false}
        samities={[]} employees={[]}
    />);
    check('CollectionReports: mounts without ReferenceError', m.container.textContent.includes(en.collection_details_report));
    await unmount(m);
}

async function scenarioDashboardMounts() {
    // Dashboard (Module 2 / Step 24): KPI cards, charts and the samity /
    // officer tables render the server-computed figures; the branch
    // filter appears when the user can see more than one branch; the
    // mega-hub stays below the overview.
    __setPage(baseProps());
    const Wrapped = withI18n(Dashboard);
    const props = {
        asOn: '2026-10-28',
        kpis: {
            members: { total: 5, active: 4, new_month: 2, closed: 1 },
            samities: { total: 2, active: 2 },
            deposit: { accounts: 3, savings: '1000.00', dps: '2300.00', fdr: '5000.00', other: '0.00', total: '8300.00' },
            loan: { disbursed_count: 2, disbursed_amount: '18000.00', active: 2, outstanding: '18995.79', overdue_amount: '3290.85', overdue_loans: 1 },
            collection: { today: '8800.00', today_loan: '500.00', today_deposit: '8300.00', month: '8800.00' },
        },
        trend: [
            { date: '2026-10-27', total: '0.00' },
            { date: '2026-10-28', total: '8800.00' },
        ],
        samityRows: [
            { code: '0001', name: 'Talgachi Samity', branch_id: 2, officer_id: 3, members: 4, savings: '8300.00', outstanding: '18995.79', collection: '8800.00' },
        ],
        officerRows: [
            { officer: '000003 — Field Officer', samities: 1, members: 4, savings: '8300.00', outstanding: '18995.79', collection: '8800.00' },
        ],
        branches: baseProps().branches,
        selectedBranch: null,
    };
    const m = mount(<Wrapped {...props} />);
    const text = m.container.textContent;
    check('Dashboard: mounts with title', text.includes(en.dashboard));
    check('Dashboard: shows as-on business date', text.includes('2026-10-28'));
    check('Dashboard: today collection headline 8,800.00', text.includes('8,800.00'));
    check('Dashboard: loan outstanding 18,995.79', text.includes('18,995.79'));
    check('Dashboard: overdue amount 3,290.85 flagged', text.includes('3,290.85'));
    check('Dashboard: samity table row renders', text.includes('Talgachi Samity'));
    check('Dashboard: officer table row renders', text.includes('000003 — Field Officer'));
    check('Dashboard: trend chart svg renders', !!m.container.querySelector('svg[aria-label]'));
    // The dashboard filter is the select offering the "all branches"
    // option (AppLayout has its own branch switcher select).
    const filterOf = (c) => [...c.querySelectorAll('select')]
        .find((s) => [...s.options].some((o) => o.textContent === en['dash.all_branches']));
    const select = filterOf(m.container);
    check('Dashboard: branch filter lists all + 2 branches', !!select && select.options.length === 3);
    check('Dashboard: hub search stays reachable below', !!m.container.querySelector('input'));
    await unmount(m);

    // Single-branch users get no filter.
    const m1 = mount(<Wrapped {...props} branches={[baseProps().branches[1]]} />);
    check('Dashboard: no branch filter for single-branch user', !filterOf(m1.container));
    await unmount(m1);

    // BN render: title switches with the locale.
    __setPage(baseProps({ locale: 'bn', translations: bn, translations_version: 'harness-bn' }));
    const m2 = mount(<Wrapped {...props} />);
    check('Dashboard: BN title renders', m2.container.textContent.includes(bn.dashboard));
    await unmount(m2);
    __setPage(baseProps());
}

const scenarios = [
    scenarioPrimaryButton,
    scenarioActionButton,
    scenarioLoginUseForm,
    scenarioBusinessDayRawRouter,
    scenarioMemberClosing,
    scenarioExcelImport,
    scenarioSchedulePicker,
    scenarioPageMounts,
    scenarioProposalMemberSync,
    scenarioLoanOpeningMemberSync,
    scenarioDepositOpeningMemberSync,
    scenarioPassbookIssueMemberSync,
    scenarioChequeBookIssueMemberSync,
    scenarioDepositShareMemberSync,
    scenarioDepositShareModeSync,
    scenarioReportPagesMount,
    scenarioRegistersDateFormat,
    scenarioRegistersTypeManager,
    scenarioCollectionSheetPosting,
    scenarioCollectionReportsMount,
    scenarioDashboardMounts,
];

for (const fn of scenarios) {
    try {
        await fn();
    } catch (e) {
        failures.push(`${fn.name} threw: ${e.message}`);
        console.log(`FAIL ${fn.name} threw: ${e.stack?.split('\n').slice(0, 4).join(' | ')}`);
    }
}
console.log(`HARNESS_RESULT {"pass":${passCount},"fail":${failures.length},"failures":${JSON.stringify(failures)}}`);
