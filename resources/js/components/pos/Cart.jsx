import { Ban, BadgePercent, Pencil, Printer, Trash2, User, Utensils, X } from 'lucide-react';
import { Tag } from '@/components/ui';
import { cx, money } from '@/lib/format';
import { lineDetail, lineDiscount, lineGross } from './cartLines';
import { QtyStepper } from './ItemDialog';

/**
 * Right side of the POS (pos-react `.cart`): order type and details, the lines already
 * sent (kitchen status, void) and the new ones (qty, edit, discount, remove), the bill,
 * and Hold / Send — or, for a placed order with nothing new, Bill / Split / Pay (split
 * parts are paid one by one). On phones it is a bottom sheet (`open`).
 */
export default function Cart({
    order,
    types,
    type,
    onType,
    details,
    sent,
    lines,
    index,
    errors,
    bill,
    rates,
    orderDiscount,
    can,
    open,
    processing,
    onClose,
    onDetails,
    onQty,
    onEdit,
    onRemove,
    onLineDiscount,
    onVoid,
    onOrderDiscount,
    onServiceCharge,
    onHold,
    onSend,
    onDiscard,
    saveLabel,
    billing,
}) {
    const count = sent.reduce((n, l) => n + l.quantity, 0) + lines.reduce((n, l) => n + l.quantity, 0);
    const typeLocked = order && !order.is_draft;
    const firstError = Object.entries(errors).find(([k]) => k !== 'pin')?.[1];
    const hasKitchen = lines.some((l) => l.type !== 'ready_item' || index.get(`${l.type}:${l.id}`)?.kitchen);
    const placed = order && !order.is_draft;
    const billMode = placed && lines.length === 0 && !saveLabel; // nothing new: bill / split / pay
    const due = placed ? Math.max(0, Number(order.due)) : 0;

    return (
        <div className={cx('cart', open && 'cart-open')}>
            <div className="cart-hdr">
                <span>{order ? (order.is_draft ? 'Held Order' : `Order ${order.code}`) : 'New Order'}</span>
                <span className="cart-hdr-right">
                    <span className="tag tag-neutral">{count} ITEMS</span>
                    <button type="button" className="cart-close" aria-label="Close cart" onClick={onClose}>
                        <X size={16} strokeWidth={1.5} />
                    </button>
                </span>
            </div>

            <div className="pos-order-bar">
                <div className="pos-type-tabs" role="radiogroup" aria-label="Order type">
                    {types.map((t) => (
                        <button
                            key={t.value}
                            type="button"
                            role="radio"
                            aria-checked={type === t.value}
                            className={cx('pos-cat', type === t.value && 'active')}
                            disabled={typeLocked && type !== t.value}
                            onClick={() => onType(t.value)}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
                <div className="pos-chips">
                    {type === 'dine_in' && (
                        <button type="button" className={cx('pos-chip', !details.table && 'is-missing', errors.table && 'is-error')} onClick={() => onDetails('table')}>
                            <Utensils size={12} strokeWidth={1.5} />
                            {details.table ? details.tableText : 'Pick a table'}
                        </button>
                    )}
                    <button
                        type="button"
                        className={cx('pos-chip', type === 'delivery' && !details.customer && 'is-missing', (errors.customer || errors.address) && 'is-error')}
                        onClick={() => onDetails('customer')}
                    >
                        <User size={12} strokeWidth={1.5} />
                        {details.customer ? details.customerText : type === 'delivery' ? 'Pick the customer' : 'Customer'}
                    </button>
                </div>
            </div>

            <div className="cart-items">
                {sent.map((l) => (
                    <div key={l.id} className={cx('cart-item', 'is-sent', l.voided && 'is-voided')}>
                        <div className="cart-item-name">{l.full_name}</div>
                        <div className="cart-item-price mono">{money(l.line_total)}</div>
                        <div className="cart-item-detail">
                            {l.quantity} × {money(Number(l.unit_price) + Number(l.modifiers_total))}
                            {l.modifiers.length > 0 && ` · ${l.modifiers.map((m) => m.name).join(', ')}`}
                            {l.picks.length > 0 && ` · ${l.picks.filter((p) => !p.voided).map((p) => p.name).join(', ')}`}
                            {Number(l.discount_amount) > 0 && ` · −${money(l.discount_amount)}`}
                            {l.voided && ` · Voided: ${l.void_reason}`}
                        </div>
                        <div className="cart-item-tools">
                            {!l.voided && l.kitchen_status && <Tag tone={l.kitchen_status.tone}>{l.kitchen_status.label}</Tag>}
                            {!l.voided && can.void && (
                                <button type="button" className="cart-tool" aria-label={`Void ${l.full_name}`} title="Void" onClick={() => onVoid(l)}>
                                    <Ban size={13} strokeWidth={1.5} />
                                </button>
                            )}
                        </div>
                    </div>
                ))}

                {sent.length > 0 && lines.length > 0 && <div className="cart-divider">New — not sent yet</div>}

                {lines.map((l, i) => {
                    const item = index.get(`${l.type}:${l.id}`);
                    const detail = lineDetail(l);
                    const off = lineDiscount(l, item);
                    const error = errors[`items.${i}`];
                    const editable = item && (l.type === 'deal' || item.variants?.length > 0 || item.groups?.length > 0);
                    const short = item?.type === 'ready_item' && l.quantity > item.stock;
                    return (
                        <div key={l.key} className={cx('cart-item', 'is-new', error && 'has-error')}>
                            <div className="cart-item-name">
                                {l.name}
                                {!item && <span className="cart-item-warn"> · not on the menu</span>}
                            </div>
                            <div className="cart-item-price mono">{money(lineGross(l, item) - off)}</div>
                            <div className="cart-item-detail">
                                {detail}
                                {l.notes && `${detail ? ' · ' : ''}“${l.notes}”`}
                                {l.discount && ` · ${l.discount.name} −${money(off)}`}
                                {short && <span className="cart-item-warn"> · only {item.stock} in stock</span>}
                            </div>
                            <div className="cart-item-tools">
                                <QtyStepper value={l.quantity} onChange={(q) => onQty(l.key, q)} />
                                {editable && (
                                    <button type="button" className="cart-tool" aria-label="Edit" title="Edit" onClick={() => onEdit(l)}>
                                        <Pencil size={13} strokeWidth={1.5} />
                                    </button>
                                )}
                                {can.discount && (
                                    <button type="button" className={cx('cart-tool', l.discount && 'on')} aria-label="Line discount" title="Discount" onClick={() => onLineDiscount(l)}>
                                        <BadgePercent size={13} strokeWidth={1.5} />
                                    </button>
                                )}
                                <button type="button" className="cart-tool" aria-label={`Remove ${l.name}`} title="Remove" onClick={() => onRemove(l.key)}>
                                    <Trash2 size={13} strokeWidth={1.5} />
                                </button>
                            </div>
                            {error && <div className="cart-item-error">{error}</div>}
                        </div>
                    );
                })}

                {count === 0 && <p className="pos-empty">Tap items to add them.</p>}
            </div>

            <div className="cart-summary">
                <div className="cart-row">
                    <span>Subtotal</span>
                    <span className="mono">{money(bill.items)}</span>
                </div>
                <div className="cart-row">
                    {can.discount ? (
                        <button type="button" className="text-link" onClick={onOrderDiscount}>
                            {orderDiscount ? `Discount (${orderDiscount.name})` : 'Discount'}
                        </button>
                    ) : (
                        <span>Discount</span>
                    )}
                    <span className="cart-discount mono">−{money(bill.discount)}</span>
                </div>
                {type === 'dine_in' && rates.serviceRate > 0 && (
                    <div className="cart-row">
                        {can.serviceCharge ? (
                            <button type="button" className="text-link" onClick={onServiceCharge}>
                                Service {rates.serviceRate}% {rates.serviceRemoved ? '(removed — add back)' : '(remove)'}
                            </button>
                        ) : (
                            <span>Service {rates.serviceRate}%</span>
                        )}
                        <span className="mono">{money(bill.service)}</span>
                    </div>
                )}
                {type === 'delivery' && (
                    <div className="cart-row">
                        <span>Delivery fee</span>
                        <span className="mono">{money(bill.delivery)}</span>
                    </div>
                )}
                {rates.taxRate > 0 && (
                    <div className="cart-row">
                        <span>
                            {rates.taxName} {rates.taxRate}%
                        </span>
                        <span className="mono">{money(bill.tax)}</span>
                    </div>
                )}
                {bill.roundOff !== 0 && (
                    <div className="cart-row">
                        <span>Round off</span>
                        <span className="mono">{bill.roundOff > 0 ? '+' : '−'}{money(Math.abs(bill.roundOff))}</span>
                    </div>
                )}
                <div className="cart-row total">
                    <span>Total</span>
                    <span>{money(bill.grand)}</span>
                </div>
                {placed && Number(order.paid_total) > 0 && (
                    <>
                        <div className="cart-row">
                            <span>Paid</span>
                            <span className="mono">{money(order.paid_total)}</span>
                        </div>
                        <div className="cart-row cart-due">
                            <span>Due</span>
                            <span className="mono">{money(Math.max(0, bill.grand - Number(order.paid_total)))}</span>
                        </div>
                    </>
                )}
                {placed && lines.length === 0 && order.splits?.length > 0 && (
                    <div className="cart-splits">
                        {order.splits.map((s) => (
                            <div key={s.id} className="cart-row cart-split">
                                <span>
                                    {s.label} · <span className="mono">{money(s.amount)}</span>
                                </span>
                                <span className="cart-split-tools">
                                    {billing.canPrint && Number(s.due) > 0 && (
                                        <button type="button" className="cart-tool" title={`Print ${s.label}’s bill`} aria-label={`Print ${s.label}’s bill`} onClick={() => billing.onPrintBill(s)}>
                                            <Printer size={13} strokeWidth={1.5} />
                                        </button>
                                    )}
                                    {Number(s.due) > 0 ? (
                                        billing.canPay && (
                                            <button type="button" className="text-link" onClick={() => billing.onPay(s)}>
                                                Pay {money(s.due)}
                                            </button>
                                        )
                                    ) : (
                                        <span className="tag tag-accent">PAID</span>
                                    )}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {firstError && <div className="pos-error">{firstError}</div>}

            <div className="cart-actions">
                {order?.is_draft && (
                    <button type="button" className="cart-hold cart-discard" disabled={processing} onClick={onDiscard} title="Discard held order">
                        Discard
                    </button>
                )}
                {can.hold && (!order || order.is_draft) && (
                    <button type="button" className="cart-hold" disabled={processing || lines.length === 0} onClick={onHold}>
                        Hold
                    </button>
                )}
                {billMode ? (
                    <>
                        {billing.canPrint && (
                            <button type="button" className="cart-hold" disabled={processing} onClick={() => (due > 0 ? billing.onPrintBill(null) : billing.onPrintReceipt())}>
                                {due > 0 ? 'Bill' : 'Receipt'}
                            </button>
                        )}
                        {billing.canSplit && due > 0 && Number(order.paid_total) === 0 && (
                            <button type="button" className="cart-hold" disabled={processing} onClick={billing.onSplit}>
                                {order.splits?.length ? 'Re-split' : 'Split'}
                            </button>
                        )}
                        <button type="button" className="cart-pay" disabled={processing || !billing.canPay || due <= 0} onClick={() => billing.onPay(null)}>
                            {due > 0 ? `Pay · ${money(due)}` : 'Paid — waiting for the kitchen'}
                        </button>
                    </>
                ) : (
                    <>
                        {billing.canPay && lines.length > 0 && (
                            <button type="button" className="cart-hold" disabled={processing} onClick={billing.onSendPay} title="Send, then take payment">
                                Send &amp; Pay
                            </button>
                        )}
                        <button type="button" className="cart-pay" disabled={processing || (lines.length === 0 && !saveLabel)} onClick={onSend}>
                            {processing
                                ? 'Saving…'
                                : saveLabel
                                  ? saveLabel
                                  : !order || order.is_draft
                                    ? `${hasKitchen ? 'Send to Kitchen' : 'Place Order'} · ${money(bill.grand)}`
                                    : `Send ${lines.length} New · ${money(bill.grand)}`}
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}
