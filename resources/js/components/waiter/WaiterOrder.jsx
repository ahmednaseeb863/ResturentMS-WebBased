import { Check, Pencil, Trash2, X } from 'lucide-react';
import { Tag } from '@/components/ui';
import { lineDetail, lineGross } from '@/components/pos/cartLines';
import { QtyStepper } from '@/components/pos/ItemDialog';
import { cx, money } from '@/lib/format';

/** Uuids of the lines (and deal picks) the kitchen finished that are not served yet. */
export function readyUuids(order) {
    if (!order || order.is_draft) return [];
    return order.lines.flatMap((l) => {
        if (l.voided) return [];
        if (l.picks.length) return l.picks.filter((p) => !p.voided && p.kitchen_status === 'ready').map((p) => p.id);
        return l.kitchen_status?.value === 'ready' ? [l.id] : [];
    });
}

/**
 * Right side / bottom sheet of the waiter table screen (same `.cart` look as the POS):
 * the lines already sent with their kitchen status — ready ones can be marked served —
 * then the new lines (qty, edit, remove), the bill so far, and Send / Ask for Bill.
 */
export default function WaiterOrder({
    order,
    table,
    lines,
    index,
    guests,
    onGuests,
    bill,
    errors,
    open,
    processing,
    blocked,
    onClose,
    onQty,
    onEdit,
    onRemove,
    onSend,
    onServe,
    onBill,
    canServe,
    canBill,
}) {
    const sent = order && !order.is_draft ? order.lines : [];
    const ready = readyUuids(order);
    const count = sent.filter((l) => !l.voided).reduce((n, l) => n + l.quantity, 0) + lines.reduce((n, l) => n + l.quantity, 0);
    const firstError = Object.values(errors)[0];
    const due = order && !order.is_draft ? Number(order.due) : 0;
    const newCount = lines.reduce((n, l) => n + l.quantity, 0);
    const hasKitchen = lines.some((l) => l.type !== 'ready_item' || index.get(`${l.type}:${l.id}`)?.kitchen);

    return (
        <div className={cx('cart', 'waiter-order', open && 'cart-open')}>
            <div className="cart-hdr">
                <span>{order ? (order.is_draft ? `${table.name} · Held` : `${table.name} · ${order.code}`) : `${table.name} · New Order`}</span>
                <span className="cart-hdr-right">
                    <span className="tag tag-neutral">{count} ITEMS</span>
                    <button type="button" className="cart-close" aria-label="Close order" onClick={onClose}>
                        <X size={16} strokeWidth={1.5} />
                    </button>
                </span>
            </div>

            <div className="pos-order-bar waiter-order-bar">
                {order ? (
                    <span className="waiter-order-meta">
                        {[order.guests && `${order.guests} guests`, order.waiter?.name, order.status.label].filter(Boolean).join(' · ')}
                    </span>
                ) : (
                    <label className="waiter-guests">
                        <span>Guests</span>
                        <QtyStepper value={guests ?? 0} min={0} max={99} onChange={(g) => onGuests(g || null)} />
                    </label>
                )}
                {order?.bill_requested && <Tag tone="accent">Bill asked</Tag>}
            </div>

            <div className="cart-items">
                {ready.length > 1 && canServe && (
                    <button type="button" className="waiter-serve-all" disabled={processing} onClick={() => onServe(ready)}>
                        <Check size={15} strokeWidth={1.5} /> Serve all ready ({ready.length})
                    </button>
                )}

                {sent.map((l) => (
                    <div key={l.id} className={cx('cart-item', 'is-sent', l.voided && 'is-voided', !l.voided && l.kitchen_status?.value === 'ready' && 'is-ready')}>
                        <div className="cart-item-name">
                            {l.quantity} × {l.full_name}
                        </div>
                        <div className="cart-item-price mono">{money(l.line_total)}</div>
                        <div className="cart-item-detail">
                            {[l.modifiers.map((m) => m.name).join(', '), l.notes && `“${l.notes}”`, l.voided && `Voided: ${l.void_reason}`].filter(Boolean).join(' · ')}
                        </div>
                        <div className="cart-item-tools">
                            {!l.voided && l.kitchen_status && <Tag tone={l.kitchen_status.tone}>{l.kitchen_status.label}</Tag>}
                            {!l.voided && canServe && l.kitchen_status?.value === 'ready' && (
                                <button type="button" className="waiter-serve" disabled={processing} onClick={() => onServe([l.id])}>
                                    <Check size={13} strokeWidth={1.5} /> Served
                                </button>
                            )}
                        </div>
                        {!l.voided &&
                            l.picks
                                .filter((p) => !p.voided)
                                .map((p) => (
                                    <div key={p.id} className="waiter-pick">
                                        <span>
                                            {p.quantity} × {p.name}
                                        </span>
                                        <span className="waiter-pick-tools">
                                            {p.kitchen_status && <span className={cx('waiter-pick-status', `is-${p.kitchen_status}`)}>{p.kitchen_status}</span>}
                                            {canServe && p.kitchen_status === 'ready' && (
                                                <button type="button" className="waiter-serve" disabled={processing} onClick={() => onServe([p.id])}>
                                                    <Check size={13} strokeWidth={1.5} /> Served
                                                </button>
                                            )}
                                        </span>
                                    </div>
                                ))}
                    </div>
                ))}

                {sent.length > 0 && lines.length > 0 && <div className="cart-divider">New — not sent yet</div>}

                {lines.map((l, i) => {
                    const item = index.get(`${l.type}:${l.id}`);
                    const detail = lineDetail(l);
                    const error = errors[`items.${i}`];
                    const editable = item && (l.type === 'deal' || item.variants?.length > 0 || item.groups?.length > 0);
                    return (
                        <div key={l.key} className={cx('cart-item', 'is-new', error && 'has-error')}>
                            <div className="cart-item-name">
                                {l.name}
                                {!item && <span className="cart-item-warn"> · not on the menu</span>}
                            </div>
                            <div className="cart-item-price mono">{money(lineGross(l, item))}</div>
                            <div className="cart-item-detail">
                                {detail}
                                {l.notes && `${detail ? ' · ' : ''}“${l.notes}”`}
                            </div>
                            <div className="cart-item-tools">
                                <QtyStepper value={l.quantity} onChange={(q) => onQty(l.key, q)} />
                                {editable && (
                                    <button type="button" className="cart-tool" aria-label="Edit" title="Edit" onClick={() => onEdit(l)}>
                                        <Pencil size={13} strokeWidth={1.5} />
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

                {count === 0 && <p className="pos-empty">Tap items on the menu to add them.</p>}
            </div>

            <div className="cart-summary">
                {bill.service > 0 && (
                    <div className="cart-row">
                        <span>Service charge</span>
                        <span className="mono">{money(bill.service)}</span>
                    </div>
                )}
                {bill.tax > 0 && (
                    <div className="cart-row">
                        <span>Tax</span>
                        <span className="mono">{money(bill.tax)}</span>
                    </div>
                )}
                <div className="cart-row total">
                    <span>Total</span>
                    <span>{money(bill.grand)}</span>
                </div>
                {order && Number(order.paid_total) > 0 && (
                    <div className="cart-row cart-due">
                        <span>Due</span>
                        <span className="mono">{money(Math.max(0, bill.grand - Number(order.paid_total)))}</span>
                    </div>
                )}
            </div>

            {blocked && <div className="pos-error">{blocked}</div>}
            {!blocked && firstError && <div className="pos-error">{firstError}</div>}

            <div className="cart-actions">
                {lines.length > 0 ? (
                    <button type="button" className="cart-pay" disabled={processing || Boolean(blocked)} onClick={onSend}>
                        {processing ? 'Sending…' : `${hasKitchen ? 'Send to Kitchen' : 'Add to Order'} · ${newCount} item${newCount === 1 ? '' : 's'}`}
                    </button>
                ) : (
                    order &&
                    !order.is_draft &&
                    canBill && (
                        <button type="button" className="cart-pay" disabled={processing || due <= 0} onClick={onBill}>
                            {due <= 0 ? 'Paid' : order.bill_requested ? `Ask for Bill Again · ${money(due)}` : `Ask for Bill · ${money(due)}`}
                        </button>
                    )
                )}
            </div>
        </div>
    );
}
