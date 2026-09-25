import { Field, FormSection, Input, Select } from '@/components/ui';
import { Package } from 'lucide-react';
import { qty } from '@/lib/format';

export const stockDefaults = (item, defaultUnit = '') => ({
    stock_unit: item?.stock_unit?.id ?? defaultUnit,
    purchase_unit: item?.purchase_unit?.id ?? '',
    purchase_unit_factor: item?.purchase_unit_factor ? String(Number(item.purchase_unit_factor)) : '',
    alert_level: item?.alert_level != null ? String(Number(item.alert_level)) : '',
    opening_stock: '',
    unit_cost: '',
});

/**
 * Stock unit, purchase unit (+ pack size when it is not convertible), alert level and —
 * when adding — opening stock and cost. Shared by raw materials and ready items.
 */
export default function StockFields({ item, data, setData, errors, units }) {
    const isEdit = Boolean(item?.id);
    const stock = units.find((u) => u.value === data.stock_unit);
    const purchase = units.find((u) => u.value === data.purchase_unit);
    const needsFactor = purchase && stock && purchase.family !== stock.family;
    const locked = isEdit && item.has_movements;

    return (
        <>
            <FormSection icon={Package} title="Stock" />
            <Field
                label="Stock unit"
                required
                error={errors.stock_unit}
                hint={locked ? `Locked — stock is recorded in ${item.stock_unit?.short_name}` : 'Stock is counted in this unit'}
            >
                <Select
                    value={data.stock_unit}
                    invalid={errors.stock_unit}
                    placeholder="Pick a unit…"
                    options={units.map((u) => ({ value: u.value, label: `${u.label} — ${u.name}` }))}
                    disabled={locked}
                    onChange={(e) => setData('stock_unit', e.target.value)}
                />
            </Field>
            <Field label="Low-stock alert at" error={errors.alert_level} hint="Empty = no alert">
                <div className="stg-number">
                    <Input
                        type="number"
                        min="0"
                        step="any"
                        inputMode="decimal"
                        value={data.alert_level}
                        invalid={errors.alert_level}
                        onChange={(e) => setData('alert_level', e.target.value)}
                    />
                    <span className="stg-unit">{stock?.label ?? ''}</span>
                </div>
            </Field>
            <Field label="Bought in" error={errors.purchase_unit} hint="Optional, e.g. carton, crate">
                <Select
                    value={data.purchase_unit}
                    invalid={errors.purchase_unit}
                    placeholder="Same as stock unit"
                    options={units.filter((u) => u.value !== data.stock_unit)}
                    onChange={(e) => setData('purchase_unit', e.target.value)}
                />
            </Field>
            {needsFactor ? (
                <Field label={`${stock.label} in one ${purchase.label}`} required error={errors.purchase_unit_factor}>
                    <Input
                        type="number"
                        min="0"
                        step="any"
                        inputMode="decimal"
                        value={data.purchase_unit_factor}
                        invalid={errors.purchase_unit_factor}
                        placeholder="24"
                        onChange={(e) => setData('purchase_unit_factor', e.target.value)}
                    />
                </Field>
            ) : (
                <div className="cust-field" />
            )}

            {isEdit ? (
                <Field label="Current stock" full hint="Changes only through Add stock, purchases, sales and kitchen use">
                    <div className="stock-readonly">{qty(item.current_stock, item.stock_unit?.short_name)}</div>
                </Field>
            ) : (
                <>
                    <Field label="Opening stock" error={errors.opening_stock} hint="What is on hand today">
                        <div className="stg-number">
                            <Input
                                type="number"
                                min="0"
                                step="any"
                                inputMode="decimal"
                                value={data.opening_stock}
                                invalid={errors.opening_stock}
                                onChange={(e) => setData('opening_stock', e.target.value)}
                            />
                            <span className="stg-unit">{stock?.label ?? ''}</span>
                        </div>
                    </Field>
                    <Field label={`Cost per ${stock?.label ?? 'unit'}`} error={errors.unit_cost} hint="Starts the average cost">
                        <Input
                            type="number"
                            min="0"
                            step="any"
                            inputMode="decimal"
                            value={data.unit_cost}
                            invalid={errors.unit_cost}
                            onChange={(e) => setData('unit_cost', e.target.value)}
                        />
                    </Field>
                </>
            )}
        </>
    );
}
