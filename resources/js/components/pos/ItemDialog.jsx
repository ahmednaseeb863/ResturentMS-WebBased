import { useState } from 'react';
import { Minus, Plus } from 'lucide-react';
import { Button, Dialog, Field, Input } from '@/components/ui';
import { cx, money } from '@/lib/format';
import { newKey } from './cartLines';

/** Quantity − n + (big touch buttons). */
export function QtyStepper({ value, onChange, min = 1, max = 999 }) {
    return (
        <div className="pos-qty">
            <button type="button" className="pos-qty-btn" aria-label="Less" disabled={value <= min} onClick={() => onChange(value - 1)}>
                <Minus size={14} strokeWidth={1.5} />
            </button>
            <span className="pos-qty-value mono">{value}</span>
            <button type="button" className="pos-qty-btn" aria-label="More" disabled={value >= max} onClick={() => onChange(value + 1)}>
                <Plus size={14} strokeWidth={1.5} />
            </button>
        </div>
    );
}

/**
 * Size + add-ons + quantity + note for a menu item (new line or editing one in the cart).
 * Groups follow their pick rules; a group of "pick 1" behaves like radio buttons.
 */
export default function ItemDialog({ item, line, onSave, onClose }) {
    const [variant, setVariant] = useState(
        () => line?.variant?.id ?? item.variants.find((v) => v.is_default)?.id ?? (item.variants.length === 1 ? item.variants[0].id : null),
    );
    const [picked, setPicked] = useState(() => line?.modifiers.map((m) => m.id) ?? []);
    const [quantity, setQuantity] = useState(line?.quantity ?? 1);
    const [notes, setNotes] = useState(line?.notes ?? '');
    const [tried, setTried] = useState(false);

    const size = item.variants.find((v) => v.id === variant) ?? null;
    const modifiers = item.groups.flatMap((g) => g.modifiers).filter((m) => picked.includes(m.id));
    const unit = (size?.price ?? item.price) + modifiers.reduce((s, m) => s + m.price, 0);

    const problems = [
        ...(item.variants.length && !size ? ['Pick a size.'] : []),
        ...item.groups
            .map((g) => ({ g, n: g.modifiers.filter((m) => picked.includes(m.id)).length }))
            .filter(({ g, n }) => n < g.min || (g.max !== null && n > g.max))
            .map(({ g }) => `${g.name}: ${g.rule.toLowerCase()}.`),
    ];

    function toggle(group, id) {
        const inGroup = group.modifiers.map((m) => m.id);
        setPicked((current) => {
            if (current.includes(id)) return current.filter((x) => x !== id);
            if (group.max === 1) return [...current.filter((x) => !inGroup.includes(x)), id];
            const count = current.filter((x) => inGroup.includes(x)).length;
            return group.max !== null && count >= group.max ? current : [...current, id];
        });
    }

    function save() {
        setTried(true);
        if (problems.length) return;
        onSave({
            key: line?.key ?? newKey(),
            type: 'menu_item',
            id: item.id,
            name: item.name,
            variant: size,
            // keep the menu order of add-ons
            modifiers,
            picks: [],
            quantity,
            notes: notes.trim(),
            discount: line?.discount ?? null,
        });
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={item.name}
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
            {item.description && <p className="ui-dialog-text">{item.description}</p>}

            {item.variants.length > 0 && (
                <div className="pos-opt-group">
                    <div className="pos-opt-title">
                        Size <span className="pos-opt-rule">Required · pick 1</span>
                    </div>
                    <div className="pos-opt-grid">
                        {item.variants.map((v) => (
                            <button
                                key={v.id}
                                type="button"
                                className={cx('pos-opt', variant === v.id && 'on')}
                                aria-pressed={variant === v.id}
                                onClick={() => setVariant(v.id)}
                            >
                                <span>{v.name}</span>
                                <span className="pos-opt-price mono">{money(v.price)}</span>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {item.groups.map((g) => (
                <div key={g.id} className="pos-opt-group">
                    <div className="pos-opt-title">
                        {g.name} <span className="pos-opt-rule">{g.rule}</span>
                    </div>
                    <div className="pos-opt-grid">
                        {g.modifiers.map((m) => (
                            <button
                                key={m.id}
                                type="button"
                                className={cx('pos-opt', picked.includes(m.id) && 'on')}
                                aria-pressed={picked.includes(m.id)}
                                onClick={() => toggle(g, m.id)}
                            >
                                <span>{m.name}</span>
                                <span className="pos-opt-price mono">{m.price ? `+${money(m.price)}` : 'Free'}</span>
                            </button>
                        ))}
                    </div>
                </div>
            ))}

            <div className="pos-opt-footer">
                <Field label="Quantity">
                    <QtyStepper value={quantity} onChange={setQuantity} />
                </Field>
                <Field label="Note for the kitchen" className="pos-opt-note">
                    <Input value={notes} maxLength={150} placeholder="e.g. no onion" onChange={(e) => setNotes(e.target.value)} />
                </Field>
            </div>

            {tried && problems.length > 0 && <div className="field-error">{problems[0]}</div>}
        </Dialog>
    );
}
