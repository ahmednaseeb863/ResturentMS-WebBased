import { useState } from 'react';
import { Minus, Plus, X } from 'lucide-react';
import { Button, Dialog, Input, Select } from '@/components/ui';
import { cx, qty } from '@/lib/format';

/** ± step for a unit: grams / ml in tens, kg / litres in 50 g, pieces one by one. */
function stepOf(unit) {
    const u = (unit ?? '').toLowerCase();
    if (u === 'g' || u === 'ml') return 10;
    if (u === 'kg' || u === 'l') return 0.05;
    return 1;
}

const round3 = (n) => Math.round(n * 1000) / 1000;

/**
 * "Confirm raw material used" (PLAN §4.16): the recipe quantities of each line, pre-filled.
 * The cook taps Confirm, or adjusts a quantity (with a reason) / adds a material first.
 * `rows`: [{ id, name, quantity, extras, materials: [{ id, name, unit, expected, expected_text }] }]
 * `materials`: [{ id, name, unit }] raw materials that can be added.
 */
export default function ConsumptionDialog({ title, rows, materials, processing, error, onConfirm, onClose }) {
    const [lines, setLines] = useState(() =>
        Object.fromEntries(
            rows.map((row) => [row.id, row.materials.map((m) => ({ ...m, actual: String(m.expected), reason: '', added: false }))]),
        ),
    );

    const update = (itemId, materialId, patch) =>
        setLines((all) => ({ ...all, [itemId]: all[itemId].map((m) => (m.id === materialId ? { ...m, ...patch } : m)) }));

    function nudge(itemId, material, direction) {
        const next = round3(Math.max(0, (Number(material.actual) || 0) + direction * stepOf(material.unit)));
        update(itemId, material.id, { actual: String(next) });
    }

    function add(itemId, materialId) {
        const material = materials.find((m) => m.id === materialId);
        if (!material) return;
        setLines((all) => ({
            ...all,
            [itemId]: [...all[itemId], { id: material.id, name: material.name, unit: material.unit, expected: 0, actual: '', reason: '', added: true }],
        }));
    }

    const remove = (itemId, materialId) => setLines((all) => ({ ...all, [itemId]: all[itemId].filter((m) => m.id !== materialId) }));

    function confirm() {
        onConfirm(
            rows.map((row) => ({
                item: row.id,
                materials: lines[row.id].map((m) => ({ id: m.id, quantity: Number(m.actual) || 0, reason: m.reason.trim() || null })),
            })),
        );
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={title}
            className="kds-use-dialog"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" disabled={processing} onClick={confirm}>
                        Confirm &amp; Ready
                    </Button>
                </>
            }
        >
            <p className="ui-dialog-text">Raw materials used — change a quantity if it was more or less than the recipe.</p>

            {rows.map((row) => {
                const used = lines[row.id].map((m) => m.id);
                const addable = materials.filter((m) => !used.includes(m.id)).map((m) => ({ value: m.id, label: `${m.name} (${m.unit})` }));

                return (
                    <section key={row.id} className="kds-use-item">
                        <div className="kds-use-title">
                            {row.quantity} × {row.name}
                            {row.extras.length > 0 && <span className="cell-muted"> + {row.extras.join(', ')}</span>}
                        </div>

                        {lines[row.id].map((m) => {
                            const changed = Number(m.actual) !== Number(m.expected);
                            return (
                                <div key={m.id} className={cx('kds-use-row', changed && 'kds-use-changed')}>
                                    <div className="kds-use-name">
                                        <span>{m.name}</span>
                                        <span className="cell-muted">{m.added ? 'Not in recipe' : `Recipe ${m.expected_text}`}</span>
                                    </div>
                                    <div className="kds-use-qty">
                                        <button type="button" className="pos-qty-btn" aria-label={`Less ${m.name}`} onClick={() => nudge(row.id, m, -1)}>
                                            <Minus size={14} strokeWidth={1.5} />
                                        </button>
                                        <Input
                                            mono
                                            inputMode="decimal"
                                            className="kds-use-input"
                                            value={m.actual}
                                            aria-label={`${m.name} used`}
                                            onChange={(e) => update(row.id, m.id, { actual: e.target.value.replace(/[^0-9.]/g, '') })}
                                        />
                                        <button type="button" className="pos-qty-btn" aria-label={`More ${m.name}`} onClick={() => nudge(row.id, m, 1)}>
                                            <Plus size={14} strokeWidth={1.5} />
                                        </button>
                                        <span className="kds-use-unit">{m.unit}</span>
                                        {m.added && (
                                            <button type="button" className="cust-close" aria-label={`Remove ${m.name}`} onClick={() => remove(row.id, m.id)}>
                                                <X size={12} strokeWidth={1.5} />
                                            </button>
                                        )}
                                    </div>
                                    {changed && (
                                        <Input
                                            className="kds-use-reason"
                                            value={m.reason}
                                            maxLength={150}
                                            placeholder={`Why ${Number(m.actual) > Number(m.expected) ? 'more' : 'less'}? e.g. bigger fillet`}
                                            onChange={(e) => update(row.id, m.id, { reason: e.target.value })}
                                        />
                                    )}
                                    {changed && !m.added && (
                                        <span className="kds-use-diff mono">
                                            {Number(m.actual) > Number(m.expected) ? '+' : '−'}
                                            {qty(Math.abs(round3((Number(m.actual) || 0) - m.expected)), m.unit)}
                                        </span>
                                    )}
                                </div>
                            );
                        })}

                        {addable.length > 0 && (
                            <Select
                                className="kds-use-add"
                                value=""
                                placeholder="+ Add a raw material…"
                                options={addable}
                                onChange={(e) => add(row.id, e.target.value)}
                            />
                        )}
                    </section>
                );
            })}

            {error && <div className="field-error">{error}</div>}
        </Dialog>
    );
}
