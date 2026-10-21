import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CheckCircle2, X, XCircle } from 'lucide-react';
import { t } from '../lib/i18n';

let seq = 0;

/**
 * App-wide toast stack (rendered once by AppLayout): fires on the shared
 * Inertia flash — success (green) / error (red) — and whenever a form
 * submit comes back with validation errors (red; first error plus a
 * count of the rest). Auto-dismisses after ~4s, close button per toast,
 * stacked top-right and safe at 360px.
 */
export default function Toasts() {
    const { props } = usePage();
    const [toasts, setToasts] = useState([]);

    const dismiss = (id) => setToasts((cur) => cur.filter((x) => x.id !== id));

    const push = (items) => {
        const withIds = items.map((i) => ({ ...i, id: ++seq }));
        setToasts((cur) => [...cur, ...withIds].slice(-4));
        withIds.forEach((i) => setTimeout(() => dismiss(i.id), 4000));
    };

    // Flash fires once per server response carrying it (object identity
    // changes on every Inertia visit, so this never replays on local
    // re-renders).
    useEffect(() => {
        const fresh = [];
        if (props.flash?.success) fresh.push({ kind: 'success', message: props.flash.success });
        if (props.flash?.error) fresh.push({ kind: 'error', message: props.flash.error });
        if (fresh.length > 0) push(fresh);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [props.flash]);

    useEffect(() => {
        const messages = Object.values(props.errors ?? {}).flat().filter(Boolean);
        if (messages.length === 0) return;
        const message = messages.length > 1
            ? `${messages[0]} (${t('and_n_more', { count: messages.length - 1 })})`
            : messages[0];
        push([{ kind: 'error', message }]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [props.errors]);

    if (toasts.length === 0) return null;

    return (
        <div
            className="pointer-events-none fixed right-3 top-3 z-[100] flex w-[calc(100vw-24px)] max-w-sm flex-col gap-2 sm:right-4 sm:top-4"
            role="status"
            aria-live="polite"
        >
            {toasts.map((toast) => (
                <div
                    key={toast.id}
                    className={`pointer-events-auto flex items-start gap-2.5 rounded-xl px-4 py-3 text-sm shadow-lg ring-1 ${
                        toast.kind === 'success'
                            ? 'bg-emerald-600 text-white ring-emerald-700/40'
                            : 'bg-red-600 text-white ring-red-700/40'
                    }`}
                >
                    {toast.kind === 'success'
                        ? <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" />
                        : <XCircle className="mt-0.5 h-5 w-5 shrink-0" />}
                    <span className="min-w-0 flex-1 leading-snug">{toast.message}</span>
                    <button
                        type="button"
                        onClick={() => dismiss(toast.id)}
                        className="shrink-0 rounded-md p-0.5 hover:bg-white/15"
                        aria-label={t('close')}
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            ))}
        </div>
    );
}
