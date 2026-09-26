import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Ban, BadgePercent, ChevronLeft, ClipboardList, History, Printer, Scale, ShoppingCart, Ticket, XCircle } from 'lucide-react';
import { Button, CheckItem, Corners, Dialog, Field, FormGrid, Input, PageBody, PageStatus, PageToolbar, Tag, Textarea } from '@/components/ui';
import DiscountDialog from '@/components/pos/DiscountDialog';
import PinDialog from '@/components/orders/PinDialog';
import VoidDialog from '@/components/orders/VoidDialog';
import useCan from '@/hooks/useCan';
import { cx, date, dateTime, money, number } from '@/lib/format';

/** Order detail (pos-react Sales Invoice detail): who / where, the bill, lines, kitchen tickets, history. */
export default function OrderShow({ order, history, tickets, consumptions, discounts, rules }) {
    const can = useCan();
    const [dialog, setDialog] = useState(null);
    const [processing, setProcessing] = useState(false);
    const open = order.is_open;
    const close = () => setDialog(null);

    /** PUT with a manager PIN round-trip when the server asks for one. */
    function send(url, data, pinMessage, pin = null) {
        router.put(url, { ...data, pin }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: close,
            onError: (errors) => {
                if (errors.pin) setDialog({ kind: 'pin', url, data, message: pinMessage, error: pin ? errors.pin : null });
                else setDialog((d) => ({ ...d, error: Object.values(errors)[0] }));
            },
        });
    }

    const lines = order.lines;
    const live = lines.filter((l) => !l.voided);
    const orderDiscount = order.discount
        ? { discount: order.discount.preset, type: order.discount.type, value: Number(order.discount.value), reason: order.discount.reason, name: order.discount.name }
        : null;
    const lineDiscounts = live.reduce((s, l) => s + Number(l.discount_amount), 0);

    return (
        <PageBody>
            <PageToolbar
                title={`Order ${order.is_draft ? '(held)' : order.code}`}
                primary={
                    open &&
                    can('pos.index') && (
                        <Button variant="primary" icon={ShoppingCart} href={route('pos.index', { order: order.id })}>
                            Open in POS
                        </Button>
                    )
                }
            >
                <Tag tone={order.status.tone}>{order.status.label}</Tag>
                {open && !order.is_draft && can('orders.discount') && (
                    <Button icon={BadgePercent} onClick={() => setDialog({ kind: 'discount' })}>
                        Discount
                    </Button>
                )}
                {open && order.type.value === 'dine_in' && Number(order.service_charge_rate) > 0 && can('orders.service-charge') && rules.service_removable && (
                    <Button
                        onClick={() =>
                            send(
                                route('orders.service-charge', order.id),
                                { remove: !order.service_charge_removed },
                                'A manager must approve removing the service charge with their PIN.',
                            )
                        }
                        disabled={processing}
                    >
                        {order.service_charge_removed ? 'Add Service Charge' : 'Remove Service Charge'}
                    </Button>
                )}
                {open && !order.is_draft && can('orders.cancel') && (
                    <Button variant="danger" icon={XCircle} onClick={() => setDialog({ kind: 'cancel' })}>
                        Cancel Order
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                <span>
                    Taken by {order.created_by?.name} · {order.source}
                </span>
                <span>Business day {date(order.business_date)}</span>
            </PageStatus>

            <div className="pi-detail-page">
                <button type="button" className="pi-back-btn" onClick={() => router.visit(route('orders.index'))}>
                    <ChevronLeft size={13} strokeWidth={1.5} />
                    Back to Orders
                </button>

                {order.status.value === 'cancelled' && (
                    <div className="order-cancelled">
                        Cancelled {dateTime(order.cancelled_at)} by {order.cancelled_by?.name} — {order.cancel_reason}
                    </div>
                )}

                <div className="pi-meta-grid">
                    <div className="scard">
                        <Corners />
                        <div className="pi-card-rows">
                            <span className="pi-card-label">Order #</span>
                            <span className="mono">{order.number ? order.code : 'Held — not sent'}</span>
                            <span className="pi-card-label">Type</span>
                            <span>{order.type.label}</span>
                            <span className="pi-card-label">Placed</span>
                            <span>{order.placed_at ? dateTime(order.placed_at) : '—'}</span>
                            <span className="pi-card-label">Taken By</span>
                            <span>{order.created_by?.name}</span>
                            <span className="pi-card-label">Payment</span>
                            <span>{order.payment_status.label}</span>
                        </div>
                    </div>

                    <div className="scard">
                        <Corners />
                        <div className="pi-card-rows">
                            {order.table && (
                                <>
                                    <span className="pi-card-label">Table</span>
                                    <span>
                                        {order.table.name}
                                        {order.guests ? ` · ${order.guests} guests` : ''}
                                    </span>
                                    <span className="pi-card-label">Waiter</span>
                                    <span>{order.waiter?.name ?? '—'}</span>
                                </>
                            )}
                            <span className="pi-card-label">Customer</span>
                            <span className="order-strong">{order.customer?.name ?? 'Walk-in'}</span>
                            {order.customer && (
                                <>
                                    <span className="pi-card-label">Phone</span>
                                    <span className="mono">{order.customer.phone}</span>
                                </>
                            )}
                            {order.delivery && (
                                <>
                                    <span className="pi-card-label">Deliver To</span>
                                    <span>{order.delivery.address}</span>
                                    <span className="pi-card-label">Delivery</span>
                                    <span>{order.delivery.status}</span>
                                </>
                            )}
                            {order.notes && (
                                <>
                                    <span className="pi-card-label">Notes</span>
                                    <span className="cell-muted">{order.notes}</span>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="scard">
                        <Corners />
                        <div className="pi-card-rows">
                            <span className="pi-card-label">Subtotal</span>
                            <span className="mono">{money(order.items_total)}</span>
                            <span className="pi-card-label">Discount</span>
                            <span className="mono order-minus">{Number(order.discount_total) > 0 ? `(${money(order.discount_total)})` : '—'}</span>
                            {Number(order.service_charge_rate) > 0 && (
                                <>
                                    <span className="pi-card-label">Service {number(order.service_charge_rate)}%</span>
                                    <span className="mono">{order.service_charge_removed ? 'Removed' : money(order.service_charge)}</span>
                                </>
                            )}
                            {order.type.value === 'delivery' && (
                                <>
                                    <span className="pi-card-label">Delivery Fee</span>
                                    <span className="mono">{money(order.delivery_fee)}</span>
                                </>
                            )}
                            {Number(order.tax_rate) > 0 && (
                                <>
                                    <span className="pi-card-label">
                                        {order.tax_name} {number(order.tax_rate)}%
                                    </span>
                                    <span className="mono">{money(order.tax_total)}</span>
                                </>
                            )}
                            {Number(order.round_off) !== 0 && (
                                <>
                                    <span className="pi-card-label">Round Off</span>
                                    <span className="mono">{money(order.round_off)}</span>
                                </>
                            )}
                            <span className="pi-card-label">Grand Total</span>
                            <span className="order-grand">{money(order.grand_total)}</span>
                            <span className="pi-card-label">Paid</span>
                            <span className="mono">{money(order.paid_total)}</span>
                        </div>
                    </div>
                </div>

                <div className="section-title">
                    <ClipboardList strokeWidth={1.5} />
                    Line Items
                </div>
                {order.is_draft ? (
                    <HeldLines items={order.held_items} />
                ) : (
                    <div className="rgrid-wrap">
                        <table className="rgrid">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Item</th>
                                    <th className="text-center">Qty</th>
                                    <th className="text-right">Price</th>
                                    <th className="text-right">Discount</th>
                                    <th>Kitchen</th>
                                    <th className="text-right">Line Total</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {lines.map((l, i) => (
                                    <tr key={l.id} className={cx(l.voided && 'order-voided')}>
                                        <td className="cell-muted">{i + 1}</td>
                                        <td>
                                            <span className="order-strong">{l.full_name}</span>
                                            {(l.modifiers.length > 0 || l.picks.length > 0 || l.notes) && (
                                                <span className="cell-sub">
                                                    {[
                                                        l.modifiers.map((m) => m.name).join(', '),
                                                        l.picks.map((p) => `${p.quantity} × ${p.name}`).join(', '),
                                                        l.notes && `“${l.notes}”`,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                            )}
                                            {l.voided && (
                                                <span className="cell-sub order-void-note">
                                                    Voided {dateTime(l.voided_at)} by {l.voided_by} — {l.void_reason}
                                                    {l.void_wasted ? ' (wasted)' : ''}
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-center">{l.quantity}</td>
                                        <td className="mono text-right">{number(Number(l.unit_price) + Number(l.modifiers_total))}</td>
                                        <td className="mono text-right order-minus">
                                            {Number(l.discount_amount) > 0 ? `(${number(l.discount_amount)})` : '—'}
                                        </td>
                                        <td>
                                            {l.kitchen_status ? (
                                                <>
                                                    <Tag tone={l.kitchen_status.tone}>{l.kitchen_status.label}</Tag>
                                                    <span className="cell-sub">{[l.station, l.ticket].filter(Boolean).join(' · ')}</span>
                                                </>
                                            ) : (
                                                <span className="cell-muted">—</span>
                                            )}
                                        </td>
                                        <td className="mono text-right order-strong">{number(l.line_total)}</td>
                                        <td className="text-right">
                                            {open && !l.voided && can('orders.items.void') && (
                                                <button type="button" className="cart-tool" title="Void" aria-label={`Void ${l.full_name}`} onClick={() => setDialog({ kind: 'void', line: l })}>
                                                    <Ban size={13} strokeWidth={1.5} />
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                {Number(order.discount_total) > 0 && (
                                    <tr>
                                        <td colSpan={6} className="pi-tfoot-label">
                                            Discount{order.discount ? ` — ${order.discount.name} ${order.discount.value_text}` : ''}
                                        </td>
                                        <td className="pi-tfoot-total order-minus">({number(order.discount_total)})</td>
                                        <td />
                                    </tr>
                                )}
                                <tr>
                                    <td colSpan={6} className="pi-tfoot-label">
                                        Grand Total
                                    </td>
                                    <td className="pi-tfoot-total">{money(order.grand_total)}</td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}

                {tickets.length > 0 && (
                    <>
                        <div className="section-title order-section">
                            <Ticket strokeWidth={1.5} />
                            Kitchen Tickets
                        </div>
                        <div className="rgrid-wrap">
                            <table className="rgrid">
                                <thead>
                                    <tr>
                                        <th>Ticket</th>
                                        <th>Station</th>
                                        <th className="text-center">Lines</th>
                                        <th>Status</th>
                                        <th>Sent</th>
                                        <th>Printed</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {tickets.map((t) => (
                                        <tr key={t.id}>
                                            <td className="mono">{t.code}</td>
                                            <td>{t.station}</td>
                                            <td className="text-center">{t.items}</td>
                                            <td>{t.status}</td>
                                            <td className="mono cell-muted">{dateTime(t.sent_at)}</td>
                                            <td className="mono cell-muted">{t.printed_at ? dateTime(t.printed_at) : '—'}</td>
                                            <td className="text-right">
                                                {t.printer && can('kitchen.tickets.reprint') && (
                                                    <button
                                                        type="button"
                                                        className="cart-tool"
                                                        title={`Reprint on ${t.printer}`}
                                                        aria-label={`Reprint ${t.code}`}
                                                        onClick={() => router.post(route('kitchen.tickets.reprint', t.id), {}, { preserveScroll: true })}
                                                    >
                                                        <Printer size={13} strokeWidth={1.5} />
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                {consumptions.length > 0 && (
                    <>
                        <div className="section-title order-section">
                            <Scale strokeWidth={1.5} />
                            Raw Materials Used
                        </div>
                        <div className="rgrid-wrap">
                            <table className="rgrid">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Raw Material</th>
                                        <th className="text-right">Recipe</th>
                                        <th className="text-right">Used</th>
                                        <th className="text-right">Difference</th>
                                        <th>Confirmed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {consumptions.map((c, i) => (
                                        <tr key={i}>
                                            <td>{c.item}</td>
                                            <td className="order-strong">{c.material}</td>
                                            <td className="mono text-right">{c.expected}</td>
                                            <td className="mono text-right">{c.actual}</td>
                                            <td className={cx('mono text-right', c.variance > 0 && 'order-minus')}>
                                                {c.variance === 0 ? '—' : c.variance_text}
                                                {c.reason && <span className="cell-sub">{c.reason}</span>}
                                            </td>
                                            <td>
                                                {c.by}
                                                <span className="cell-sub">{dateTime(c.at)}</span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                <div className="section-title order-section">
                    <History strokeWidth={1.5} />
                    History
                </div>
                <div className="rgrid-wrap">
                    <table className="rgrid">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Status</th>
                                <th>By</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.map((h, i) => (
                                <tr key={i}>
                                    <td className="mono cell-muted">{dateTime(h.at)}</td>
                                    <td>{h.from ? `${h.from} → ${h.to}` : h.to}</td>
                                    <td>{h.by ?? '—'}</td>
                                    <td className="cell-muted">{h.note ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {dialog?.kind === 'void' && <VoidDialog order={order} line={dialog.line} pinRequired={rules.pin_void} onClose={close} />}
            {dialog?.kind === 'cancel' && (
                <CancelDialog
                    order={order}
                    pinRequired={rules.pin_void}
                    processing={processing}
                    error={dialog.error}
                    onSubmit={(data) => send(route('orders.cancel', order.id), data, 'A manager must approve cancelling this order.', data.pin)}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'discount' && (
                <DiscountDialog
                    title={`Discount — Order ${order.code}`}
                    scope="order"
                    presets={discounts}
                    value={orderDiscount}
                    base={Number(order.items_total) - lineDiscounts}
                    processing={processing}
                    error={dialog.error}
                    onSave={(spec) =>
                        send(
                            route('orders.discount', order.id),
                            { discount: spec ? { discount: spec.discount, type: spec.type, value: spec.value, reason: spec.reason } : null },
                            'A manager must approve this discount with their PIN.',
                        )
                    }
                    onClose={close}
                />
            )}
            {dialog?.kind === 'pin' && (
                <PinDialog
                    message={dialog.message}
                    error={dialog.error}
                    processing={processing}
                    onSubmit={(pin) => send(dialog.url, dialog.data, dialog.message, pin)}
                    onClose={close}
                />
            )}
        </PageBody>
    );
}

function HeldLines({ items }) {
    return (
        <div className="rgrid-wrap">
            <table className="rgrid">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item (not sent yet)</th>
                        <th className="text-center">Qty</th>
                        <th className="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    {items.map((h, i) => (
                        <tr key={i}>
                            <td className="cell-muted">{i + 1}</td>
                            <td>
                                {h.name}
                                {h.notes && <span className="cell-sub">“{h.notes}”</span>}
                            </td>
                            <td className="text-center">{h.quantity}</td>
                            <td className="mono text-right">{number(h.gross - h.discount_amount)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function CancelDialog({ order, pinRequired, processing, error, onSubmit, onClose }) {
    const [reason, setReason] = useState('');
    const [wasted, setWasted] = useState(false);
    const [pin, setPin] = useState('');

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Cancel Order ${order.code}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Keep Order
                    </Button>
                    <Button variant="danger" disabled={!reason.trim() || processing} onClick={() => onSubmit({ reason, wasted, pin: pin || null })}>
                        {processing ? 'Cancelling…' : 'Cancel Order'}
                    </Button>
                </>
            }
        >
            <p className="ui-dialog-text">Every line is voided and the table is freed. Ready items go back to stock unless they were wasted.</p>
            <FormGrid>
                <Field label="Reason" required full>
                    <Textarea value={reason} autoFocus maxLength={255} onChange={(e) => setReason(e.target.value)} />
                </Field>
                <div className="cust-field cust-field-full">
                    <CheckItem checked={wasted} onChange={setWasted}>
                        Food / drinks were already made or served (wasted)
                    </CheckItem>
                </div>
                {pinRequired && (
                    <Field label="Manager PIN" required full>
                        <Input mono type="password" inputMode="numeric" autoComplete="off" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))} />
                    </Field>
                )}
            </FormGrid>
            {error && <div className="field-error">{error}</div>}
        </Dialog>
    );
}
