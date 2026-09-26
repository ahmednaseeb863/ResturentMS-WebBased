import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button, Dialog, Field, FormGrid, Input, Select } from '@/components/ui';
import { cx, money } from '@/lib/format';

/**
 * Dine-in details: table (grouped by area, with its status), waiter and guests. A table
 * with another open order can't be picked — open that order instead.
 */
export default function TableDialog({ tables, waiters, value, orderId, onSave, onClose }) {
    const [table, setTable] = useState(value.table);
    const [waiter, setWaiter] = useState(value.waiter ?? '');
    const [guests, setGuests] = useState(value.guests ?? '');

    const areas = [...new Set(tables.map((t) => t.area ?? 'Tables'))];

    return (
        <Dialog
            open
            onClose={onClose}
            title="Table & Waiter"
            className="pos-table-dialog"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" disabled={!table} onClick={() => onSave({ table, waiter: waiter || null, guests: guests ? Number(guests) : null })}>
                        Done
                    </Button>
                </>
            }
        >
            {tables.length === 0 && <p className="ui-dialog-text">No active tables — add them under Tables.</p>}

            {areas.map((area) => (
                <div key={area} className="pos-opt-group">
                    <div className="pos-opt-title">{area}</div>
                    <div className="pos-table-grid">
                        {tables
                            .filter((t) => (t.area ?? 'Tables') === area)
                            .map((t) => {
                                const other = t.order && t.order.id !== orderId;
                                return (
                                    <button
                                        key={t.id}
                                        type="button"
                                        className={cx('pos-table', `pt-${t.status}`, table === t.id && 'on')}
                                        aria-pressed={table === t.id}
                                        onClick={() => (other ? router.visit(route('pos.index', { order: t.order.id })) : setTable(t.id))}
                                        title={other ? `Open ${t.order.code}` : undefined}
                                    >
                                        <span className="pos-table-name">{t.name}</span>
                                        <span className="pos-table-sub">
                                            {other ? `${t.order.code} · ${money(t.order.total)}` : `${t.capacity} seats · ${t.status}`}
                                        </span>
                                    </button>
                                );
                            })}
                    </div>
                </div>
            ))}

            <FormGrid>
                <Field label="Waiter">
                    <Select value={waiter} placeholder="No waiter" options={waiters} onChange={(e) => setWaiter(e.target.value)} />
                </Field>
                <Field label="Guests">
                    <Input mono type="number" min="1" max="500" inputMode="numeric" value={guests} onChange={(e) => setGuests(e.target.value)} />
                </Field>
            </FormGrid>
        </Dialog>
    );
}
