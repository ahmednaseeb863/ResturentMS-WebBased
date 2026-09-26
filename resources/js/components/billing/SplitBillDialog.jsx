import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button, Dialog } from '@/components/ui';
import { QtyStepper } from '@/components/pos/ItemDialog';
import { cx, money } from '@/lib/format';

const round2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
const WHOLE_ABOVE = 12; // lines with more units are given out whole

/** Same shares as App\Actions\SplitBill::equalParts (cents left over go to the last part). */
function equalShares(grand, parts) {
    const share = Math.floor((grand * 100) / parts) / 100;
    return Array.from({ length: parts }, (_, i) => (i === parts - 1 ? round2(grand - share * (parts - 1)) : share));
}

/** One row per unit (small lines) or per line, each given to a guest (0-based). */
function unitsOf(lines, splits) {
    const owner = new Map(); // "line:index" → part
    splits.forEach((s, part) => {
        s.items.forEach(({ item, quantity }) => {
            for (let n = 0; n < quantity; n++) {
                let i = 0;
                while (owner.has(`${item}:${i}`)) i++;
                owner.set(`${item}:${i}`, part);
            }
        });
    });

    return lines.flatMap((l) => {
        const value = (Number(l.gross) - Number(l.discount_amount)) / l.quantity;
        if (l.quantity > WHOLE_ABOVE) return [{ key: `${l.id}:0`, line: l.id, name: `${l.quantity} × ${l.full_name}`, quantity: l.quantity, value: value * l.quantity, part: owner.get(`${l.id}:0`) ?? 0 }];
        return Array.from({ length: l.quantity }, (_, i) => ({
            key: `${l.id}:${i}`,
            line: l.id,
            name: l.quantity > 1 ? `${l.full_name} (${i + 1} of ${l.quantity})` : l.full_name,
            quantity: 1,
            value,
            part: owner.get(`${l.id}:${i}`) ?? 0,
        }));
    });
}

/**
 * Split the bill (PLAN §4.13) equally or by the items each guest had. Their share of
 * discount, service charge and tax follows their items. Only before any money is taken.
 */
export default function SplitBillDialog({ order, onClose }) {
    const lines = order.lines.filter((l) => !l.voided);
    const grand = Number(order.grand_total);
    const [mode, setMode] = useState(order.split_mode ?? 'equal');
    const [parts, setParts] = useState(Math.max(2, order.splits?.length || 2));
    const [units, setUnits] = useState(() => unitsOf(lines, order.split_mode === 'items' ? order.splits : []));
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);

    const total = units.reduce((s, u) => s + u.value, 0);
    const shares =
        mode === 'equal'
            ? equalShares(grand, parts)
            : (() => {
                  const raw = Array.from({ length: parts }, (_, p) =>
                      total > 0 ? round2((grand * units.filter((u) => u.part === p).reduce((s, u) => s + u.value, 0)) / total) : 0,
                  );
                  raw[parts - 1] = round2(raw[parts - 1] + grand - raw.reduce((s, a) => s + a, 0));
                  return raw;
              })();
    const emptyGuest = mode === 'items' && shares.some((_, p) => !units.some((u) => u.part === p));

    function changeParts(n) {
        setParts(n);
        setUnits((list) => list.map((u) => (u.part >= n ? { ...u, part: n - 1 } : u)));
    }

    function send(data) {
        router.put(route('orders.split', order.id), data, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errors) => setError(Object.values(errors)[0]),
            onSuccess: onClose,
        });
    }

    function save() {
        if (mode === 'equal') return send({ mode, parts });
        const assignments = Array.from({ length: parts }, (_, p) => {
            const byLine = new Map();
            units.filter((u) => u.part === p).forEach((u) => byLine.set(u.line, (byLine.get(u.line) ?? 0) + u.quantity));
            return [...byLine].map(([item, quantity]) => ({ item, quantity }));
        });
        send({ mode, parts, assignments });
    }

    return (
        <Dialog
            open
            onClose={onClose}
            className="split-dialog"
            title={`Split Bill — ${order.code}`}
            footer={
                <>
                    {order.split_mode && (
                        <Button variant="ghost" className="pos-dialog-left" disabled={processing} onClick={() => send({ mode: null })}>
                            Remove split
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" disabled={processing || emptyGuest} onClick={save}>
                        {processing ? 'Saving…' : `Split into ${parts}`}
                    </Button>
                </>
            }
        >
            <div className="cash-type-buttons pos-disc-types" role="radiogroup" aria-label="Split how">
                {[
                    ['equal', 'Equally'],
                    ['items', 'By items'],
                ].map(([value, label]) => (
                    <button key={value} type="button" role="radio" aria-checked={mode === value} className={cx('cash-type-btn', mode === value && 'on')} onClick={() => setMode(value)}>
                        {label}
                    </button>
                ))}
            </div>

            <div className="split-guests">
                <span>Guests</span>
                <QtyStepper value={parts} min={2} max={mode === 'equal' ? 20 : 10} onChange={changeParts} />
            </div>

            {mode === 'items' && (
                <div className="split-units">
                    {units.map((u) => (
                        <div key={u.key} className="split-unit">
                            <div className="split-unit-name">
                                {u.name}
                                <span className="cell-sub mono">{money(u.value)}</span>
                            </div>
                            <div className="split-unit-parts" role="radiogroup" aria-label={`Guest for ${u.name}`}>
                                {Array.from({ length: parts }, (_, p) => (
                                    <button
                                        key={p}
                                        type="button"
                                        role="radio"
                                        aria-checked={u.part === p}
                                        className={cx('split-part-btn', u.part === p && 'on')}
                                        onClick={() => setUnits((list) => list.map((x) => (x.key === u.key ? { ...x, part: p } : x)))}
                                    >
                                        {p + 1}
                                    </button>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <div className="shift-close-summary split-summary">
                {shares.map((amount, p) => (
                    <div key={p} className="shift-close-row">
                        <span>
                            Guest {p + 1}
                            {mode === 'items' && <span className="cell-muted"> · {units.filter((u) => u.part === p).reduce((n, u) => n + u.quantity, 0)} items</span>}
                        </span>
                        <span className="mono">{money(amount)}</span>
                    </div>
                ))}
                <div className="shift-close-row shift-close-expected">
                    <span>Bill total</span>
                    <span className="mono">{money(grand)}</span>
                </div>
            </div>
            {emptyGuest && <p className="field-hint">Every guest needs at least one item.</p>}
            {error && <div className="field-error">{error}</div>}
        </Dialog>
    );
}
