import { Check, Play, Printer, RotateCcw, Utensils } from 'lucide-react';
import { Button, Corners } from '@/components/ui';
import { cx } from '@/lib/format';

/** "4:05", "1:02:10" since `from`. */
export function elapsed(from, now) {
    const seconds = Math.max(0, Math.floor((now - new Date(from).getTime()) / 1000));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = String(seconds % 60).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
}

/** normal → amber → red by minutes waiting (Kitchen settings); ready tickets are done. */
function urgency(ticket, now, rules) {
    if (ticket.status.value === 'ready' || ticket.status.value === 'served') return 'done';
    const minutes = (now - new Date(ticket.sent_at).getTime()) / 60000;
    if (minutes >= rules.red_after) return 'late';
    if (minutes >= rules.amber_after) return 'slow';
    return 'fresh';
}

const ITEM_STATUS = { pending: 'New', preparing: 'Cooking', ready: 'Ready', served: 'Served' };

/**
 * One kitchen ticket on the board: order / table / waiter, the lines (tap a line to mark it
 * ready), and the next step — Start → Ready → Served (Recall brings it back).
 */
export default function TicketCard({ ticket, now, rules, showStation, can, busy, onStart, onReady, onServe, onRecall, onReprint }) {
    const status = ticket.status.value;
    const tone = urgency(ticket, now, rules);
    const order = ticket.order;
    const where = order.table ?? order.customer ?? order.type.label;
    const meta = [order.type.value === 'dine_in' || where === order.type.label ? null : order.type.label, order.guests && `${order.guests} guests`, order.waiter]
        .filter(Boolean)
        .join(' · ');
    const canReady = can('kitchen.tickets.ready') && (status === 'pending' || status === 'preparing');

    return (
        <article className={cx('kds-ticket', `kds-${tone}`, `kds-status-${status}`)}>
            <Corners />
            <header className="kds-ticket-head">
                <div className="kds-ticket-title">
                    <span className="kds-order">{order.code}</span>
                    <span className="kds-where">{where}</span>
                </div>
                <div className="kds-ticket-clock">
                    <span className="kds-timer mono">{elapsed(status === 'ready' ? ticket.completed_at : ticket.sent_at, now)}</span>
                    <span className="kds-ticket-code">{ticket.code}</span>
                </div>
            </header>
            {(meta || showStation) && (
                <div className="kds-ticket-meta">
                    {meta}
                    {showStation && <span className="kds-station">{ticket.station?.name ?? 'No station'}</span>}
                </div>
            )}

            <ul className="kds-items">
                {ticket.items.map((item) => {
                    const done = item.status === 'ready' || item.status === 'served';
                    const tappable = canReady && !item.voided && !done;
                    return (
                        <li key={item.id} className={cx('kds-item', item.voided && 'kds-item-void', done && 'kds-item-done')}>
                            <button
                                type="button"
                                className="kds-item-btn"
                                disabled={!tappable || busy}
                                onClick={() => onReady(ticket, item)}
                                title={tappable ? 'Mark this item ready' : undefined}
                            >
                                <span className="kds-qty mono">{item.quantity}×</span>
                                <span className="kds-item-body">
                                    <span className="kds-item-name">{item.name}</span>
                                    {item.deal && <span className="kds-item-sub">({item.deal})</span>}
                                    {item.extras.map((extra) => (
                                        <span key={extra} className="kds-item-sub">
                                            + {extra}
                                        </span>
                                    ))}
                                    {item.notes && <span className="kds-item-note">{item.notes}</span>}
                                    {item.voided && <span className="kds-item-note">VOID — {item.void_reason}</span>}
                                </span>
                                {!item.voided && (
                                    <span className={cx('kds-item-state', `kds-state-${item.status}`)}>
                                        {done ? <Check size={14} strokeWidth={1.5} /> : ITEM_STATUS[item.status]}
                                    </span>
                                )}
                            </button>
                        </li>
                    );
                })}
            </ul>
            {order.notes && <div className="kds-ticket-note">{order.notes}</div>}

            <footer className="kds-ticket-actions">
                {status === 'pending' && can('kitchen.tickets.start') && (
                    <Button icon={Play} disabled={busy} onClick={() => onStart(ticket)}>
                        Start
                    </Button>
                )}
                {canReady && (
                    <Button variant="primary" icon={Check} disabled={busy} onClick={() => onReady(ticket, null)}>
                        Ready
                    </Button>
                )}
                {status === 'ready' && can('kitchen.tickets.serve') && (
                    <Button variant="primary" icon={Utensils} disabled={busy} onClick={() => onServe(ticket)}>
                        {order.type.value === 'dine_in' ? 'Served' : 'Collected'}
                    </Button>
                )}
                {status === 'ready' && can('kitchen.tickets.recall') && (
                    <Button variant="ghost" icon={RotateCcw} disabled={busy} onClick={() => onRecall(ticket)}>
                        Not ready
                    </Button>
                )}
                {onReprint && (
                    <button type="button" className="kds-icon-btn" onClick={() => onReprint(ticket)} aria-label={`Reprint ${ticket.code}`} title="Reprint">
                        <Printer size={15} strokeWidth={1.5} />
                    </button>
                )}
            </footer>
        </article>
    );
}
