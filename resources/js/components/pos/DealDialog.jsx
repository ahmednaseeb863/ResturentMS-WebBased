import { useState } from 'react';
import { Button, Dialog, Field, Input } from '@/components/ui';
import { cx, money } from '@/lib/format';
import { newKey } from './cartLines';
import { QtyStepper } from './ItemDialog';

/** One pick per slot of a deal ("Drink — choose 1"); a slot with one option is fixed. */
export default function DealDialog({ deal, line, onSave, onClose }) {
    const [picks, setPicks] = useState(() => {
        const start = {};
        deal.slots.forEach((s) => {
            const saved = line?.picks.find((p) => p.slot === s.id)?.option;
            start[s.id] = saved ?? (s.options.length === 1 ? s.options[0].id : s.options.find((o) => o.is_default)?.id ?? null);
        });
        return start;
    });
    const [quantity, setQuantity] = useState(line?.quantity ?? 1);
    const [notes, setNotes] = useState(line?.notes ?? '');
    const [tried, setTried] = useState(false);

    const chosen = deal.slots.map((s) => ({ slot: s, option: s.options.find((o) => o.id === picks[s.id]) ?? null }));
    const missing = chosen.find((c) => !c.option);
    const unit = deal.price + chosen.reduce((sum, c) => sum + (c.option?.extra ?? 0), 0);

    function save() {
        setTried(true);
        if (missing) return;
        onSave({
            key: line?.key ?? newKey(),
            type: 'deal',
            id: deal.id,
            name: deal.name,
            variant: null,
            modifiers: [],
            picks: chosen.map((c) => ({ slot: c.slot.id, option: c.option.id, label: c.option.label, extra: c.option.extra })),
            quantity,
            notes: notes.trim(),
            discount: line?.discount ?? null,
        });
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={deal.name}
            className="pos-item-dialog"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" onClick={save}>
                        {line ? 'Update' : 'Add'} · {money(unit * quantity)}
                    </Button>
                </>
            }
        >
            <p className="ui-dialog-text">
                {deal.description || 'Pick one of each.'} <span className="cell-muted">({deal.schedule})</span>
            </p>

            {deal.slots.map((s) => (
                <div key={s.id} className="pos-opt-group">
                    <div className="pos-opt-title">
                        {s.quantity > 1 ? `${s.quantity} × ` : ''}
                        {s.name}
                        <span className="pos-opt-rule">{s.options.length === 1 ? 'Included' : 'Pick 1'}</span>
                    </div>
                    {s.options.length === 0 ? (
                        <div className="field-error">Nothing in this slot is available now — the deal can’t be sold.</div>
                    ) : (
                        <div className="pos-opt-grid">
                            {s.options.map((o) => (
                                <button
                                    key={o.id}
                                    type="button"
                                    className={cx('pos-opt', picks[s.id] === o.id && 'on')}
                                    aria-pressed={picks[s.id] === o.id}
                                    onClick={() => setPicks((p) => ({ ...p, [s.id]: o.id }))}
                                >
                                    <span>{o.label}</span>
                                    {o.extra > 0 && <span className="pos-opt-price mono">+{money(o.extra)}</span>}
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            ))}

            <div className="pos-opt-footer">
                <Field label="Quantity">
                    <QtyStepper value={quantity} onChange={setQuantity} />
                </Field>
                <Field label="Note for the kitchen" className="pos-opt-note">
                    <Input value={notes} maxLength={150} placeholder="e.g. no ice" onChange={(e) => setNotes(e.target.value)} />
                </Field>
            </div>

            {tried && missing && <div className="field-error">Pick {missing.slot.name}.</div>}
        </Dialog>
    );
}
