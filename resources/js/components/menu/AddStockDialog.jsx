import { useForm } from '@inertiajs/react';
import { Button, Dialog, Field, FormGrid, Input, Select } from '@/components/ui';
import { money, qty } from '@/lib/format';

/**
 * Quick "add stock" for a raw material / ready item: quantity in any unit it is counted
 * or bought in, and the cost of one such unit. The server converts and updates the
 * average cost; the preview here is display only.
 */
export default function AddStockDialog({ item, units, onClose }) {
    const stockUnit = units.find((u) => u.value === item.stock_unit?.id);
    const purchase = item.purchase_unit && units.find((u) => u.value === item.purchase_unit.id);

    const options = units.filter((u) => stockUnit && u.family === stockUnit.family);
    if (purchase && !options.some((u) => u.value === purchase.value)) options.push(purchase);

    const { data, setData, post, processing, errors } = useForm({
        kind: item.kind,
        item: item.id,
        quantity: '',
        unit: purchase?.value ?? stockUnit?.value ?? '',
        unit_cost: '',
        note: '',
    });

    // quantity in the stock unit, for the preview
    const picked = units.find((u) => u.value === data.unit);
    let stockQty = Number(data.quantity) || 0;
    if (picked && stockUnit && picked.value !== stockUnit.value) {
        stockQty =
            picked.family === stockUnit.family
                ? (stockQty * picked.factor) / stockUnit.factor
                : stockQty * Number(item.purchase_unit_factor || 0);
    }
    const total = (Number(data.quantity) || 0) * (Number(data.unit_cost) || 0);

    function submit(e) {
        e.preventDefault();
        post(route('stock.add'), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Add Stock — ${item.name}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="add-stock-form" disabled={processing}>
                        {processing ? 'Adding…' : 'Add Stock'}
                    </Button>
                </>
            }
        >
            <form id="add-stock-form" onSubmit={submit} noValidate>
                <FormGrid>
                    <Field label="Quantity" required error={errors.quantity}>
                        <Input
                            type="number"
                            min="0"
                            step="any"
                            inputMode="decimal"
                            value={data.quantity}
                            invalid={errors.quantity}
                            onChange={(e) => setData('quantity', e.target.value)}
                            autoFocus
                        />
                    </Field>
                    <Field label="Unit" required error={errors.unit || errors.item}>
                        <Select
                            value={data.unit}
                            invalid={errors.unit}
                            options={options.map((u) =>
                                purchase && u.value === purchase.value && u.family !== stockUnit?.family
                                    ? { value: u.value, label: `${u.label} (${qty(item.purchase_unit_factor)} ${stockUnit?.label})` }
                                    : u,
                            )}
                            onChange={(e) => setData('unit', e.target.value)}
                        />
                    </Field>
                    <Field label={`Cost per ${picked?.label ?? 'unit'}`} error={errors.unit_cost} hint="Updates the average cost">
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
                    <Field label="Note" error={errors.note}>
                        <Input
                            value={data.note}
                            invalid={errors.note}
                            placeholder="Supplier, invoice no…"
                            onChange={(e) => setData('note', e.target.value)}
                        />
                    </Field>
                </FormGrid>
                <div className="stock-preview">
                    <span>
                        Now <b>{qty(item.current_stock, stockUnit?.label)}</b>
                    </span>
                    <span>
                        After <b>{qty(Number(item.current_stock) + stockQty, stockUnit?.label)}</b>
                    </span>
                    {total > 0 && (
                        <span>
                            Total <b>{money(total)}</b>
                        </span>
                    )}
                </div>
            </form>
        </Dialog>
    );
}
