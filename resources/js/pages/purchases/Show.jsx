import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ChevronLeft, Undo2, Wallet } from 'lucide-react';
import { Button, DataTable, Dialog, Field, FormGrid, Input, PageBody, PageStatus, PageToolbar, StatCard, StatGrid, Tag, Textarea } from '@/components/ui';
import SupplierPayDialog from '@/components/inventory/SupplierPayDialog';
import useCan from '@/hooks/useCan';
import { date, dateTime, money, qty } from '@/lib/format';

/** One purchase: lines received, payments, returns; pay it or send goods back. */
export default function PurchaseShow({ purchase, bankAccounts, myShift }) {
    const can = useCan();
    const [dialog, setDialog] = useState(null); // 'pay' | 'return'
    const returnable = purchase.items.some((i) => Number(i.returnable) > 0);

    const itemColumns = [
        {
            key: 'name',
            label: 'Item',
            render: (i) => (
                <>
                    <span className="cell-strong">{i.name}</span>
                    <span className="cell-sub">{i.kind === 'raw_material' ? 'Raw material' : 'Ready item'}</span>
                </>
            ),
        },
        {
            key: 'qty',
            label: 'Quantity',
            align: 'right',
            render: (i) => (
                <>
                    <span className="mono">{qty(i.quantity, i.unit)}</span>
                    {i.unit !== i.stock_unit && <span className="cell-sub">{qty(i.stock_quantity, i.stock_unit)}</span>}
                </>
            ),
        },
        { key: 'cost', label: 'Cost / unit', align: 'right', className: 'mono', render: (i) => money(i.unit_cost) },
        { key: 'total', label: 'Total', align: 'right', className: 'mono', render: (i) => money(i.line_total) },
        {
            key: 'returned',
            label: 'Returned',
            align: 'right',
            className: 'mono',
            render: (i) => {
                const back = Number(i.quantity) - Number(i.returnable);
                return back > 0 ? qty(back, i.unit) : '—';
            },
        },
    ];

    const paymentColumns = [
        { key: 'when', label: 'Paid', className: 'mono cell-muted', render: (p) => dateTime(p.created_at) },
        {
            key: 'method',
            label: 'From',
            render: (p) => (
                <>
                    <Tag tone={p.method.tone}>{p.method.label}</Tag>
                    <span className="cell-sub">{[p.shift?.code, p.bank?.name, p.reference_no].filter(Boolean).join(' · ')}</span>
                </>
            ),
        },
        { key: 'by', label: 'By', render: (p) => p.paid_by?.name },
        { key: 'amount', label: 'Amount', align: 'right', className: 'mono', render: (p) => money(p.amount) },
    ];

    return (
        <PageBody>
            <PageToolbar
                title={`${purchase.code} — ${purchase.supplier?.name}`}
                headTitle={purchase.code}
                primary={
                    can('suppliers.pay') &&
                    purchase.due > 0 && (
                        <Button variant="primary" icon={Wallet} onClick={() => setDialog('pay')}>
                            Pay {money(purchase.due)}
                        </Button>
                    )
                }
            >
                <Button variant="ghost" icon={ChevronLeft} href={route('purchases.index')}>
                    Purchases
                </Button>
                {can('suppliers.show') && purchase.supplier && <Button href={route('suppliers.show', purchase.supplier.id)}>Supplier</Button>}
                {can('purchases.return') && returnable && (
                    <Button icon={Undo2} onClick={() => setDialog('return')}>
                        Return Goods
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                <span>
                    Received {date(purchase.business_date)} by {purchase.received_by?.name}
                    {purchase.invoice_no && ` · invoice ${purchase.invoice_no}`}
                    {purchase.invoice_date && ` of ${date(purchase.invoice_date)}`}
                </span>
                <Tag tone={purchase.payment_status.tone}>{purchase.payment_status.label}</Tag>
            </PageStatus>

            <StatGrid>
                <StatCard
                    label="Total"
                    value={money(purchase.total)}
                    sub={`items ${money(purchase.subtotal)}${Number(purchase.discount) ? ` − ${money(purchase.discount)}` : ''}${Number(purchase.tax) ? ` + tax ${money(purchase.tax)}` : ''}`}
                    tone="neutral"
                />
                <StatCard label="Returned" value={money(purchase.returned_total)} sub={`${purchase.returns.length} returns`} tone="neutral" />
                <StatCard label="Paid" value={money(purchase.paid_total)} sub={`${purchase.payments.length} payments`} tone="neutral" />
                <StatCard label="Due" value={money(purchase.due)} sub="still owed" tone={purchase.due > 0 ? 'danger' : 'neutral'} />
            </StatGrid>

            <DataTable columns={itemColumns} rows={purchase.items} noun="lines" stack />

            {purchase.payments.length > 0 && (
                <>
                    <div className="inv-panel-title">Payments</div>
                    <DataTable columns={paymentColumns} rows={purchase.payments} noun="payments" stack />
                </>
            )}

            {purchase.returns.length > 0 && (
                <>
                    <div className="inv-panel-title">Returns</div>
                    <div className="inv-list">
                        {purchase.returns.map((r) => (
                            <div key={r.id} className="inv-list-row">
                                <span className="mono cell-strong">{r.code}</span>
                                <span>
                                    {r.lines.map((l) => `${qty(l.quantity, l.unit)} ${l.name}`).join(', ')}
                                    <span className="cell-muted"> · {r.reason}</span>
                                </span>
                                <span className="cell-muted">
                                    {dateTime(r.created_at)} · {r.admin}
                                </span>
                                <span className="mono">{money(r.total)}</span>
                            </div>
                        ))}
                    </div>
                </>
            )}

            {purchase.notes && <p className="cell-muted">{purchase.notes}</p>}

            {dialog === 'pay' && purchase.supplier && (
                <SupplierPayDialog
                    supplier={purchase.supplier}
                    purchase={purchase}
                    suggested={purchase.due}
                    bankAccounts={bankAccounts}
                    myShift={myShift}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'return' && <ReturnDialog purchase={purchase} onClose={() => setDialog(null)} />}
        </PageBody>
    );
}

/** Quantities to send back per line (in the line's unit, up to what is left) and why. */
function ReturnDialog({ purchase, onClose }) {
    const lines = purchase.items.filter((i) => Number(i.returnable) > 0);
    const { data, setData, post, processing, errors, transform } = useForm({
        lines: Object.fromEntries(lines.map((i) => [i.id, ''])),
        reason: '',
    });

    const value = lines.reduce((n, i) => n + (Number(data.lines[i.id]) || 0) * (Number(i.line_total) / Number(i.quantity)), 0);

    function submit(e) {
        e.preventDefault();
        transform((d) => ({ ...d, lines: Object.fromEntries(Object.entries(d.lines).filter(([, q]) => Number(q) > 0)) }));
        post(route('purchases.return', purchase.id), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Return goods — ${purchase.code}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="return-form" disabled={processing || value <= 0}>
                        {processing ? 'Returning…' : `Return ${money(value)}`}
                    </Button>
                </>
            }
        >
            <form id="return-form" onSubmit={submit} noValidate>
                <div className="inv-list">
                    {lines.map((i) => (
                        <div key={i.id} className="inv-return-row">
                            <span>
                                <span className="cell-strong">{i.name}</span>
                                <span className="cell-sub">up to {qty(i.returnable, i.unit)}</span>
                            </span>
                            <Input
                                mono
                                type="number"
                                min="0"
                                max={i.returnable}
                                step="any"
                                inputMode="decimal"
                                placeholder="0"
                                value={data.lines[i.id]}
                                invalid={errors[`lines.${i.id}`]}
                                onChange={(e) => setData('lines', { ...data.lines, [i.id]: e.target.value })}
                                aria-label={`Return ${i.name}`}
                            />
                            <span className="cell-muted">{i.unit}</span>
                            {errors[`lines.${i.id}`] && <div className="field-error recipe-line-error">{errors[`lines.${i.id}`]}</div>}
                        </div>
                    ))}
                </div>
                <FormGrid>
                    <Field label="Reason" required full error={errors.reason || errors.lines}>
                        <Textarea value={data.reason} placeholder="Damaged, expired, wrong item…" onChange={(e) => setData('reason', e.target.value)} />
                    </Field>
                </FormGrid>
            </form>
        </Dialog>
    );
}
