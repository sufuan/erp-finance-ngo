import { Component } from 'react';

/**
 * Last-resort render guard: a component error used to unmount the whole
 * React tree and leave a blank white page. Catch it and show a clear,
 * bilingual recovery panel instead.
 */
export default class ErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { error: null };
    }

    static getDerivedStateFromError(error) {
        return { error };
    }

    componentDidCatch(error, info) {
        // Keep the failure visible in the console for diagnosis.
        console.error('Render error:', error, info);
    }

    render() {
        if (this.state.error) {
            return (
                <div className="flex min-h-screen items-center justify-center bg-zinc-100 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow">
                        <p className="mb-2 text-lg font-semibold text-zinc-800">Something went wrong</p>
                        <p className="mb-1 text-sm text-zinc-500">কিছু একটা সমস্যা হয়েছে — পেজটি আবার লোড করুন।</p>
                        <p className="mb-4 break-words font-mono text-xs text-red-600">{String(this.state.error?.message ?? this.state.error)}</p>
                        <button type="button" onClick={() => window.location.reload()}
                            className="min-h-[44px] rounded-lg bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                            Reload / আবার লোড করুন
                        </button>
                    </div>
                </div>
            );
        }
        return this.props.children;
    }
}
