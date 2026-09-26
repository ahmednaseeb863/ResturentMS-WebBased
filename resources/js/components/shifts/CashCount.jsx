import { money, number } from '@/lib/format';

/** Empty count, largest note first: [{ denomination, quantity: '' }] (an array keeps the order). */
export function emptyCount(denominations) {
    return denominations.map((d) => ({ denomination: Number(d), quantity: '' }));
}

/** Shown total of a count (the server works out the real one). */
export function countTotal(count) {
    return count.reduce((sum, l) => sum + l.denomination * (Number(l.quantity) || 0), 0);
}

/** Count → request payload [{ denomination, quantity }] (empty rows dropped). */
export function countPayload(count) {
    return count.filter((l) => Number(l.quantity) > 0).map((l) => ({ denomination: l.denomination, quantity: Number(l.quantity) }));
}

/** Notes & coins of the drawer: quantity per denomination, line amounts and the total. */
export default function CashCount({ value, onChange, error }) {
    function set(i, quantity) {
        onChange(value.map((l, j) => (j === i ? { ...l, quantity: quantity.replace(/\D/g, '') } : l)));
    }

    return (
        <div className="cash-count">
            <div className="cash-count-grid">
                {value.map((l, i) => (
                    <label key={l.denomination} className="cash-count-row">
                        <span className="cash-count-note mono">{number(l.denomination)}</span>
                        <span className="cash-count-x">×</span>
                        <input
                            className="cust-input mono cash-count-qty"
                            type="number"
                            inputMode="numeric"
                            min="0"
                            step="1"
                            placeholder="0"
                            value={l.quantity}
                            aria-label={`Number of ${number(l.denomination)}`}
                            onChange={(e) => set(i, e.target.value)}
                        />
                        <span className="cash-count-amount mono">
                            {Number(l.quantity) > 0 ? number(l.denomination * Number(l.quantity)) : ''}
                        </span>
                    </label>
                ))}
            </div>
            <div className="cash-count-total">
                <span>Total counted</span>
                <span className="mono">{money(countTotal(value))}</span>
            </div>
            {error && <div className="field-error">{error}</div>}
        </div>
    );
}
