import { useRef, useState } from 'react';
import { Loader2 } from 'lucide-react';

export const inputCls =
    'min-h-[44px] w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-emerald-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100';

export function Field({ label, error, required = false, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-200">
                {label}
                {required && (
                    <span className="text-red-600 dark:text-red-400" aria-hidden="true"> *</span>
                )}
            </span>
            {children}
            {error && <span className="mt-1 block text-sm text-red-600 dark:text-red-400">{error}</span>}
        </label>
    );
}

export function TextInput(props) {
    return <input {...props} className={inputCls} />;
}

export function SelectInput({ children, ...props }) {
    return (
        <select {...props} className={inputCls}>
            {children}
        </select>
    );
}

/**
 * Primary submit/action button. Pass `loading` (usually a useForm
 * `processing` flag) and the button disables itself and shows a spinner
 * for the whole submit — double submissions become impossible.
 */
export function PrimaryButton({ children, loading = false, disabled = false, ...props }) {
    return (
        <button
            {...props}
            disabled={loading || disabled}
            className="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
        >
            {loading && <Loader2 className="h-4 w-4 shrink-0 animate-spin" aria-hidden="true" />}
            {children}
        </button>
    );
}

/** Small inline spinner for non-PrimaryButton submit controls. */
export function ButtonSpinner({ className = 'h-4 w-4' }) {
    return <Loader2 className={`${className} shrink-0 animate-spin`} aria-hidden="true" />;
}

/**
 * Inline action button (row-level delete / cancel / approve / post /
 * toggle) that fires an Inertia write visit. It owns its pending state:
 * while the visit is in flight the button shows a spinner and is
 * disabled, so a double-click can never fire the action twice (a
 * double-fired toggle would silently flip state back; a double-fired
 * delete/cancel would error). `fire` receives the visit callbacks to
 * spread into the router call:
 *
 *   <ActionButton fire={(cb) => router.post(url, {}, { preserveScroll: true, ...cb })}>…
 */
export function ActionButton({ fire, className = '', children, ...props }) {
    const [pending, setPending] = useState(false);
    // Ref mirror of `pending`: state updates batch, so two clicks in the
    // same tick could both read pending=false — the ref closes that hole.
    const pendingRef = useRef(false);
    const release = () => { pendingRef.current = false; setPending(false); };
    return (
        <button
            type="button"
            {...props}
            disabled={pending}
            className={`inline-flex items-center gap-1.5 disabled:opacity-50 ${className}`}
            onClick={() => {
                if (pendingRef.current) return;
                pendingRef.current = true;
                setPending(true);
                try {
                    fire({ onFinish: release });
                } catch {
                    release();
                }
            }}
        >
            {pending && <ButtonSpinner className="h-3.5 w-3.5" />}
            {children}
        </button>
    );
}

export function Card({ children, className = '' }) {
    return (
        <div className={`rounded-2xl bg-white p-4 shadow-sm sm:p-6 dark:bg-zinc-900 ${className}`}>
            {children}
        </div>
    );
}

export function ResponsiveTable({ head, children }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-left text-sm">
                <thead>
                    <tr className="border-b border-zinc-200 text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                        {head.map((h, i) => (
                            <th key={i} className="px-3 py-2 font-medium">{h}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}
