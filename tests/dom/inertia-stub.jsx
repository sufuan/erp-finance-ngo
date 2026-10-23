/**
 * Test stub for '@inertiajs/react' used ONLY by the DOM harness
 * (tests/dom/run.mjs) — never bundled into the real app.
 *
 * It mimics the documented Inertia client contract that our own
 * components rely on:
 *  - useForm: `processing` is true from submit until the visit finishes,
 *    on success AND on a validation-error return; `errors` receives the
 *    server error bag on an error finish.
 *  - router.*: visits record their options; the harness decides when a
 *    visit "finishes" via __finishVisit(), which fires onSuccess/onError
 *    and then onFinish — so a component whose button re-enables only via
 *    onFinish is proven to restore in every completion path the stub can
 *    produce (success, validation errors). A component that never wires
 *    a finish callback into its visit stays disabled in the harness —
 *    exactly the stuck-spinner failure mode this suite hunts.
 */
import { useRef, useState } from 'react';

export const __state = {
    page: { props: {}, url: '/', component: 'Harness' },
    visits: [],
};

export function __setPage(props, url = '/') {
    __state.page = { props, url, component: 'Harness' };
}

export function __pendingVisit() {
    return __state.visits.find((v) => !v.finished) ?? null;
}

export function __finishVisit(outcome = {}) {
    const visit = __pendingVisit();
    if (!visit) throw new Error('__finishVisit: no pending visit');
    visit.finished = true;
    if (outcome.errors) visit.options.onError?.(outcome.errors);
    else visit.options.onSuccess?.(__state.page);
    visit.options.onFinish?.();
    return visit;
}

function startVisit(method, url, data, options = {}) {
    const visit = { method, url, data, options, finished: false };
    __state.visits.push(visit);
    options.onStart?.();
    return visit;
}

export const router = {
    post: (url, data, options) => startVisit('post', url, data, options),
    put: (url, data, options) => startVisit('put', url, data, options),
    patch: (url, data, options) => startVisit('patch', url, data, options),
    delete: (url, options) => startVisit('delete', url, undefined, options),
    get: (url, data, options) => startVisit('get', url, data, options),
    visit: (url, options = {}) => startVisit(options.method ?? 'get', url, options.data, options),
    reload: (options = {}) => startVisit('get', __state.page.url, undefined, options),
    on: () => () => {},
};

export function usePage() {
    return __state.page;
}

export function useForm(initial = {}) {
    const [data, setDataState] = useState(initial);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});
    const [recentlySuccessful, setRecentlySuccessful] = useState(false);
    const dataRef = useRef(data);
    dataRef.current = data;
    const transformRef = useRef((d) => d);

    const setData = (key, value) => {
        if (typeof key === 'object') setDataState((prev) => ({ ...prev, ...key }));
        else setDataState((prev) => ({ ...prev, [key]: value }));
    };

    const submit = (method) => (url, options = {}) => {
        setProcessing(true);
        setErrors({});
        setRecentlySuccessful(false);
        startVisit(method, url, transformRef.current(dataRef.current), {
            ...options,
            onSuccess: (page) => {
                setRecentlySuccessful(true);
                options.onSuccess?.(page);
            },
            onError: (errs) => {
                setErrors(errs ?? {});
                options.onError?.(errs);
            },
            onFinish: () => {
                setProcessing(false);
                options.onFinish?.();
            },
        });
    };

    return {
        data,
        setData,
        errors,
        setErrors,
        processing,
        recentlySuccessful,
        post: submit('post'),
        put: submit('put'),
        patch: submit('patch'),
        delete: submit('delete'),
        get: submit('get'),
        reset: (...keys) => {
            if (keys.length === 0) setDataState(initial);
            else setDataState((prev) => {
                const next = { ...prev };
                keys.forEach((k) => { next[k] = initial[k]; });
                return next;
            });
        },
        clearErrors: (...keys) => {
            if (keys.length === 0) setErrors({});
            else setErrors((prev) => {
                const next = { ...prev };
                keys.forEach((k) => delete next[k]);
                return next;
            });
        },
        transform: (fn) => { transformRef.current = fn; },
        defaults: () => {},
        isDirty: false,
        hasErrors: Object.keys(errors).length > 0,
        setError: (key, message) => setErrors((prev) => ({ ...prev, [key]: message })),
    };
}

export function Link({ href, children, ...props }) {
    return <a href={href} {...props}>{children}</a>;
}

export function Head() {
    return null;
}
