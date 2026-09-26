import { RotateCcw } from 'lucide-react';
import { Button, Drawer, EmptyState } from '@/components/ui';
import { dateTime } from '@/lib/format';

/** Tickets served today (still-open orders) — recall one back onto the board. */
export default function ServedDrawer({ tickets, loading, canRecall, onRecall, onClose }) {
    return (
        <Drawer open onClose={onClose} title="Recently Served">
            {loading && !tickets ? (
                <p className="ui-dialog-text">Loading…</p>
            ) : !tickets?.length ? (
                <EmptyState title="Nothing served yet">Served tickets of open orders show up here.</EmptyState>
            ) : (
                <div className="pos-open-list">
                    {tickets.map((t) => (
                        <div key={t.id} className="pos-open-row kds-served-row">
                            <div className="pos-open-main">
                                <span className="pos-open-code">{t.order.code}</span>
                                <span>{t.order.table ?? t.order.customer ?? t.order.type.label}</span>
                                <span className="cell-muted">{t.code}</span>
                            </div>
                            <div className="pos-open-sub">
                                <span>
                                    {t.items
                                        .filter((i) => !i.voided)
                                        .map((i) => `${i.quantity}× ${i.name}`)
                                        .join(', ')}
                                </span>
                                <span>{dateTime(t.served_at)}</span>
                            </div>
                            {canRecall && (
                                <Button variant="ghost" icon={RotateCcw} onClick={() => onRecall(t)}>
                                    Recall
                                </Button>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </Drawer>
    );
}
