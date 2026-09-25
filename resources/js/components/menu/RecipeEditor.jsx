import { Plus, X } from 'lucide-react';
import { Button, Input, Select } from '@/components/ui';
import { money } from '@/lib/format';

/** Resource recipe (`[{ raw_material: {id}, quantity, unit: {id} }]`) → editor lines. */
export function toRecipeLines(recipe = []) {
    return recipe
        .filter((l) => l.raw_material)
        .map((l) => ({ raw_material: l.raw_material.id, quantity: String(Number(l.quantity)), unit: l.unit?.id ?? '' }));
}

/**
 * Estimated food cost of editor lines at the raw materials' average cost (display only —
 * the server recalculates). `materials` / `units` come from MenuOptions.
 */
export function recipeCost(lines, materials, units) {
    return lines.reduce((sum, line) => sum + lineCost(line, materials, units), 0);
}

function lineCost(line, materials, units) {
    const material = materials.find((m) => m.value === line.raw_material);
    const unit = units.find((u) => u.value === line.unit);
    const stockUnit = units.find((u) => u.value === material?.unit);
    const qty = Number(line.quantity);
    if (!material || !unit || !stockUnit || !qty || unit.family !== stockUnit.family) return 0;
    return ((qty * unit.factor) / stockUnit.factor) * material.cost;
}

/**
 * Raw materials one serving uses: rows of material · quantity · unit (only units the
 * material can be counted in), each with its estimated cost.
 * `errorKey` = the form path, e.g. "recipe" or "variants.1.recipe".
 */
export default function RecipeEditor({ lines, onChange, materials, units, errors = {}, errorKey = 'recipe', empty }) {
    const err = (i, field) => errors[`${errorKey}.${i}.${field}`];

    function update(index, patch) {
        onChange(lines.map((l, i) => (i === index ? { ...l, ...patch } : l)));
    }

    function pickMaterial(index, uuid) {
        const material = materials.find((m) => m.value === uuid);
        update(index, { raw_material: uuid, unit: material?.unit ?? '' });
    }

    const used = new Set(lines.map((l) => l.raw_material));

    return (
        <div className="recipe-editor">
            {lines.length === 0 && <div className="recipe-empty">{empty ?? 'No raw materials yet.'}</div>}
            {lines.map((line, i) => {
                const material = materials.find((m) => m.value === line.raw_material);
                const unitOptions = units.filter((u) => material && u.family === material.family);
                const cost = lineCost(line, materials, units);
                return (
                    <div key={i} className="recipe-line">
                        <div className="recipe-line-material">
                            <Select
                                value={line.raw_material}
                                invalid={err(i, 'raw_material')}
                                placeholder="Raw material…"
                                options={materials.filter((m) => m.value === line.raw_material || !used.has(m.value))}
                                onChange={(e) => pickMaterial(i, e.target.value)}
                                aria-label="Raw material"
                            />
                        </div>
                        <Input
                            className="recipe-line-qty"
                            type="number"
                            min="0"
                            step="any"
                            inputMode="decimal"
                            value={line.quantity}
                            invalid={err(i, 'quantity')}
                            placeholder="Qty"
                            onChange={(e) => update(i, { quantity: e.target.value })}
                            aria-label="Quantity"
                        />
                        <Select
                            className="recipe-line-unit"
                            value={line.unit}
                            invalid={err(i, 'unit')}
                            options={unitOptions}
                            disabled={!material}
                            onChange={(e) => update(i, { unit: e.target.value })}
                            aria-label="Unit"
                        />
                        <span className="recipe-line-cost">{cost ? money(cost) : '—'}</span>
                        <button
                            type="button"
                            className="cust-close"
                            onClick={() => onChange(lines.filter((_, j) => j !== i))}
                            aria-label="Remove raw material"
                        >
                            <X size={14} strokeWidth={1.5} />
                        </button>
                        {(err(i, 'raw_material') || err(i, 'quantity') || err(i, 'unit')) && (
                            <div className="field-error recipe-line-error">
                                {err(i, 'raw_material') || err(i, 'quantity') || err(i, 'unit')}
                            </div>
                        )}
                    </div>
                );
            })}
            <div className="recipe-foot">
                <Button
                    variant="ghost"
                    icon={Plus}
                    className="btn-xs"
                    disabled={materials.length === 0}
                    onClick={() => onChange([...lines, { raw_material: '', quantity: '', unit: '' }])}
                >
                    Add raw material
                </Button>
                {lines.length > 0 && (
                    <span className="recipe-total">
                        Food cost <b>{money(recipeCost(lines, materials, units))}</b>
                    </span>
                )}
            </div>
        </div>
    );
}
