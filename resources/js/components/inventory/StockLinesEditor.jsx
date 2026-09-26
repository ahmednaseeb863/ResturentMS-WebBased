import { Plus, X } from 'lucide-react';
import { Button, Input, Select } from '@/components/ui';
import { cx, money, qty } from '@/lib/format';

/** Units an item can be entered in: its stock unit's family, plus its purchase unit. */
export function unitOptions(item, units) {
    if (!item) return [];
    const stock = units.find((u) => u.value === item.stock_unit);
    const options = units.filter((u) => stock && u.family === stock.family);
    const purchase = item.purchase_unit && units.find((u) => u.value === item.purchase_unit);
    if (purchase && !options.some((u) => u.value === purchase.value)) {
        options.push({ ...purchase, label: `${purchase.label} (${qty(item.purchase_unit_factor)} ${item.stock_unit_name})` });
    }
    return options;
}

/** Quantity in the item's stock unit (display only — the server converts). */
export function toStock(item, units, quantity, unitId) {
    const q = Number(quantity) || 0;
    const stock = units.find((u) => u.value === item?.stock_unit);
    const unit = units.find((u) => u.value === unitId);
    if (!item || !stock || !unit) return 0;
    if (unit.family === stock.family) return (q * unit.factor) / stock.factor;
    return unit.value === item.purchase_unit ? q * Number(item.purchase_unit_factor || 0) : 0;
}

export const newStockLine = () => ({ key: Math.random().toString(36).slice(2), kind: '', item: '', quantity: '', unit: '', unit_cost: '' });

/** Payload of editor lines (`lines[]` of purchases / waste). */
export const stockLinesPayload = (lines) =>
    lines.map(({ kind, item, quantity, unit, unit_cost }) => ({ kind, item, quantity, unit, unit_cost: unit_cost === '' ? null : unit_cost }));

/**
 * Lines of raw materials / ready items (purchases, waste): item (grouped), quantity, any
 * unit the item is counted or bought in, and — with `withCost` — the cost per that unit.
 * Without cost, the value at the average cost is shown. `items` from StockOptions.
 */
export default function StockLinesEditor({ lines, onChange, items, units, errors = {}, withCost = false, showStock = true }) {
    const index = new Map(items.map((i) => [i.key, i]));
    const groups = [...new Set(items.map((i) => i.group))];
    const err = (i) => errors[`lines.${i}.item`] || errors[`lines.${i}.quantity`] || errors[`lines.${i}.unit`] || errors[`lines.${i}.unit_cost`];

    const update = (i, patch) => onChange(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));

    function pick(i, key) {
        const item = index.get(key);
        update(i, {
            kind: item?.kind ?? '',
            item: item?.id ?? '',
            unit: withCost ? (item?.purchase_unit ?? item?.stock_unit ?? '') : (item?.stock_unit ?? ''),
        });
    }

    const total = lines.reduce((sum, l) => {
        const item = index.get(`${l.kind}:${l.item}`);
        return sum + (withCost ? (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0) : toStock(item, units, l.quantity, l.unit) * (item?.avg_cost ?? 0));
    }, 0);

    return (
        <div className="recipe-editor">
            {lines.length === 0 && <div className="recipe-empty">No items yet.</div>}
            {lines.map((line, i) => {
                const item = index.get(`${line.kind}:${line.item}`);
                const stockQty = toStock(item, units, line.quantity, line.unit);
                const value = withCost ? (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0) : stockQty * (item?.avg_cost ?? 0);
                const short = !withCost && item && stockQty > item.current_stock;
                return (
                    <div key={line.key ?? i} className={cx('stock-line', withCost && 'with-cost')}>
                        <select
                            className={cx('cust-input stock-line-item', errors[`lines.${i}.item`] && 'is-invalid')}
                            value={line.item ? `${line.kind}:${line.item}` : ''}
                            onChange={(e) => pick(i, e.target.value)}
                            aria-label="Item"
                        >
                            <option value="">Item…</option>
                            {groups.map((g) => (
                                <optgroup key={g} label={g}>
                                    {items
                                        .filter((it) => it.group === g)
                                        .map((it) => (
                                            <option key={it.key} value={it.key}>
                                                {it.name}
                                                {it.code ? ` (${it.code})` : ''}
                                            </option>
                                        ))}
                                </optgroup>
                            ))}
                        </select>
                        <Input
                            type="number"
                            min="0"
                            step="any"
                            inputMode="decimal"
                            placeholder="Qty"
                            value={line.quantity}
                            invalid={errors[`lines.${i}.quantity`]}
                            onChange={(e) => update(i, { quantity: e.target.value })}
                            aria-label="Quantity"
                        />
                        <Select
                            value={line.unit}
                            invalid={errors[`lines.${i}.unit`]}
                            options={unitOptions(item, units)}
                            disabled={!item}
                            onChange={(e) => update(i, { unit: e.target.value })}
                            aria-label="Unit"
                        />
                        {withCost && (
                            <Input
                                type="number"
                                min="0"
                                step="any"
                                inputMode="decimal"
                                placeholder="Cost / unit"
                                value={line.unit_cost}
                                invalid={errors[`lines.${i}.unit_cost`]}
                                onChange={(e) => update(i, { unit_cost: e.target.value })}
                                aria-label="Cost per unit"
                            />
                        )}
                        <span className="recipe-line-cost">{value ? money(value) : '—'}</span>
                        <button type="button" className="cust-close" onClick={() => onChange(lines.filter((_, j) => j !== i))} aria-label="Remove line">
                            <X size={14} strokeWidth={1.5} />
                        </button>
                        {item && showStock && (
                            <div className={cx('stock-line-note', short && 'is-short')}>
                                In stock {qty(item.current_stock, item.stock_unit_name)}
                                {stockQty > 0 && line.unit !== item.stock_unit && ` · this is ${qty(stockQty, item.stock_unit_name)}`}
                                {short && ' · more than in stock'}
                            </div>
                        )}
                        {err(i) && <div className="field-error recipe-line-error">{err(i)}</div>}
                    </div>
                );
            })}
            <div className="recipe-foot">
                <Button variant="ghost" icon={Plus} className="btn-xs" disabled={items.length === 0} onClick={() => onChange([...lines, newStockLine()])}>
                    Add item
                </Button>
                {lines.length > 0 && (
                    <span className="recipe-total">
                        {withCost ? 'Subtotal' : 'Value'} <b>{money(total)}</b>
                    </span>
                )}
            </div>
            {errors.lines && <div className="field-error">{errors.lines}</div>}
        </div>
    );
}
