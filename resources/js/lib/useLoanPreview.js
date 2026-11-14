import { useEffect, useRef, useState } from 'react';

function xsrf() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Loan schedule preview — the server ScheduleEngine (POST
 * /loans/proposals/preview) stays the single source of truth for the
 * math; this hook only owns WHEN the preview runs:
 *
 *  - on mount, so a form prefilled from a proposal shows its computed
 *    fields (installment / profit / total) without the operator having
 *    to delete and re-type the loan amount;
 *  - again whenever ANY input the calculation depends on changes —
 *    product, amount, profit rate, frequency, installment count, start
 *    date, member — not only when a field loses focus;
 *  - the previous result is dropped the moment an input changes, so a
 *    figure computed from a stale input combination (e.g. the default
 *    monthly/12 while the operator is switching to daily/115) is never
 *    left on screen while the fresh preview is in flight;
 *  - responses are sequence-guarded: a slow earlier request can never
 *    overwrite the result for the latest input combination.
 *
 * Returns the preview payload, or null while there is nothing valid to
 * show (inputs incomplete, or a recalculation in flight).
 */
export default function useLoanPreview({
    loan_product_id, loan_amount, profit_rate, frequency,
    no_of_installments, installment_from_date, member_id,
}) {
    const [calc, setCalc] = useState(null);
    const seq = useRef(0);

    useEffect(() => {
        // Any input change invalidates both the displayed result and
        // any preview request still in flight for the old combination.
        const mine = ++seq.current;
        setCalc(null);
        if (!loan_amount || !no_of_installments || !installment_from_date) return undefined;

        const timer = setTimeout(async () => {
            try {
                const response = await fetch('/loans/proposals/preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
                    body: JSON.stringify({
                        loan_product_id, loan_amount, profit_rate, frequency,
                        no_of_installments, installment_from_date,
                        member_id: member_id || undefined,
                    }),
                });
                if (response.ok && seq.current === mine) setCalc(await response.json());
            } catch {
                // Network failure: leave the computed fields blank
                // rather than resurrecting a stale figure.
            }
        }, 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loan_product_id, loan_amount, profit_rate, frequency, no_of_installments, installment_from_date, member_id]);

    return calc;
}
