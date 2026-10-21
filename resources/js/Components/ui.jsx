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

export function PrimaryButton({ children, ...props }) {
    return (
        <button
            {...props}
            className="min-h-[44px] rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
        >
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
