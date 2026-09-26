import { useForm } from '@inertiajs/react';
import { Banknote, ChevronLeft, Landmark, PackageCheck } from 'lucide-react';
import { Button, Field, FormGrid, Input, PageBody, PageToolbar, Select, Textarea } from '@/components/ui';
import StockLinesEditor, { newStockLine, stockLinesPayload } from '@/components/inventory/StockLinesEditor';
import { cx, money } from '@/lib/format';

/** A titled block of the purchase form. */
function Panel({ title, sub, children }) {
    return (
        <section className="scard inv-panel">
            <div className="inv-panel-title">
                {title}
                {sub && <span className="inv-panel-sub">{sub}</span>}
            </div>
            {children}
        </section>
    );
}

/**
 * Receive a supplier invoice into stock (PLAN §4.16): lines of raw materials / ready items
 * in any unit they are bought in, invoice discount and tax, and optionally money paid now.
 */
export default function PurchaseCreate({ suppliers, supplier, items, units, bankAccounts, myShift }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        supplier,
        invoice_no: '',
        invoice_date: '',
        discount: '',
        tax: '',
        notes: '',
        lines: [newStockLine()],
        pay: { amount: '', method: myShift ? 'cash' : 'bank_transfer', bank: bankAccounts[0]?.value ?? '', reference: '' },
    });

    const subtotal = data.lines.reduce((n, l) => n + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0), 0);
    const total = Math.max(0, subtotal - (Number(data.discount) || 0) + (Number(data.tax) || 0));
    const setPay = (patch) => setData('pay', { ...data.pay, ...patch });

    function submit(e) {
        e.preventDefault();
        transform((d) => ({ ...d, lines: stockLinesPayload(d.lines), pay: Number(d.pay.amount) > 0 ? d.pay : null }));
        post(route('purchases.store'));
    }

    return (
        <PageBody>
            <PageToolbar
                title="Receive Purchase"
                primary={
                    <Button variant="primary" icon={PackageCheck} type="submit" form="purchase-form" disabled={processing}>
                        {processing ? 'Receiving…' : `Receive into Stock · ${money(total)}`}
                    </Button>
                }
            >
                <Button variant="ghost" icon={ChevronLeft} href={route('purchases.index')}>
                    Purchases
                </Button>
            </PageToolbar>

            <form id="purchase-form" onSubmit={submit} noValidate className="purchase-form">
                <Panel title="Invoice">
                    <FormGrid>
                        <Field label="Supplier" required error={errors.supplier}>
                            <Select value={data.supplier} invalid={errors.supplier} placeholder="Pick the supplier…" options={suppliers} onChange={(e) => setData('supplier', e.target.value)} />
                        </Field>
                        <Field label="Invoice no." error={errors.invoice_no}>
                            <Input mono value={data.invoice_no} onChange={(e) => setData('invoice_no', e.target.value)} />
                        </Field>
                        <Field label="Invoice date" error={errors.invoice_date} hint="Stock is added today (business day)">
                            <Input mono type="date" value={data.invoice_date} onChange={(e) => setData('invoice_date', e.target.value)} />
                        </Field>
                    </FormGrid>
                </Panel>

                <Panel title="Items" sub="Quantity in any unit the item is bought in — the cost is per that unit">
                    <StockLinesEditor lines={data.lines} onChange={(lines) => setData('lines', lines)} items={items} units={units} errors={errors} withCost />
                </Panel>

                <Panel title="Totals">
                    <FormGrid>
                        <Field label="Discount" error={errors.discount}>
                            <Input mono type="number" min="0" step="0.01" inputMode="decimal" value={data.discount} onChange={(e) => setData('discount', e.target.value)} />
                        </Field>
                        <Field label="Tax" error={errors.tax}>
                            <Input mono type="number" min="0" step="0.01" inputMode="decimal" value={data.tax} onChange={(e) => setData('tax', e.target.value)} />
                        </Field>
                        <Field label="Notes" full error={errors.notes}>
                            <Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                        </Field>
                    </FormGrid>
                    <div className="stock-preview">
                        <span>
                            Subtotal <b>{money(subtotal)}</b>
                        </span>
                        <span>
                            Total <b>{money(total)}</b>
                        </span>
                    </div>
                </Panel>

                <Panel title="Pay Now" sub="Optional — pay later from the supplier’s page">
                    <div className="cash-type-buttons pay-methods pos-disc-types" role="radiogroup" aria-label="Paid from">
                        {[
                            ['cash', myShift ? `Cash · ${myShift.code}` : 'Cash (open your shift)', Banknote, !myShift],
                            ['bank_transfer', 'Bank Transfer', Landmark, !bankAccounts.length],
                        ].map(([value, label, Icon, off]) => (
                            <button
                                key={value}
                                type="button"
                                role="radio"
                                aria-checked={data.pay.method === value}
                                className={cx('cash-type-btn', data.pay.method === value && 'on')}
                                disabled={off}
                                onClick={() => setPay({ method: value })}
                            >
                                <Icon size={14} strokeWidth={1.5} />
                                {label}
                            </button>
                        ))}
                    </div>
                    <FormGrid>
                        <Field label="Amount paid now" error={errors['pay.amount'] || errors.amount || errors.method}>
                            <Input mono type="number" min="0" step="0.01" inputMode="decimal" placeholder="0" value={data.pay.amount} onChange={(e) => setPay({ amount: e.target.value })} />
                        </Field>
                        {data.pay.method === 'bank_transfer' && (
                            <>
                                <Field label="Bank account" error={errors['pay.bank'] || errors.bank}>
                                    <Select value={data.pay.bank} options={bankAccounts} onChange={(e) => setPay({ bank: e.target.value })} />
                                </Field>
                                <Field label="Reference no.">
                                    <Input mono value={data.pay.reference} onChange={(e) => setPay({ reference: e.target.value })} />
                                </Field>
                            </>
                        )}
                    </FormGrid>
                    {total > 0 && (
                        <button type="button" className="text-link" onClick={() => setPay({ amount: String(Math.round(total * 100) / 100) })}>
                            Pay the full {money(total)}
                        </button>
                    )}
                </Panel>
            </form>
        </PageBody>
    );
}
