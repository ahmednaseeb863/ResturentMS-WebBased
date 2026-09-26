import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ArrowLeft, ClipboardList } from 'lucide-react';
import { Button, ConfirmDialog, PageToolbar } from '@/components/ui';
import DealDialog from '@/components/pos/DealDialog';
import ItemDialog from '@/components/pos/ItemDialog';
import ItemPicker from '@/components/pos/ItemPicker';
import { lineGross, newKey, payloadOf, sameLine } from '@/components/pos/cartLines';
import WaiterOrder, { readyUuids } from '@/components/waiter/WaiterOrder';
import useCan from '@/hooks/useCan';
import useLive from '@/hooks/useLive';
import { cx, money, since } from '@/lib/format';
import { bill as billOf } from '@/lib/pricing';

/**
 * One table in the waiter app (PLAN §4.11): the menu (same picker and item / deal dialogs
 * as the POS) and the table's order — items ready to serve, new items to send to the
 * kitchen, ask for the bill. Phones: the menu fills the screen, the order is a bottom
 * sheet. The order refreshes itself when the kitchen moves (polling).
 */
export default function WaiterTable() {
    const { table, order, items, deals, categories, rules, shiftOpen, tableStatuses } = usePage().props;
    const can = useCan();
    const index = useMemo(() => new Map([...items, ...deals].map((i) => [i.key, i])), [items, deals]);

    const [lines, setLines] = useState([]);
    const [guests, setGuests] = useState(null);
    const [dialog, setDialog] = useState(null); // { kind, item, line }
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [open, setOpen] = useState(false);

    useLive('kitchen', () => router.reload({ only: ['order'] }));
    useLive('floor', () => router.reload({ only: ['order', 'table', 'shiftOpen'] }));

    const placed = order && !order.is_draft;
    const close = () => setDialog(null);

    // ── bill so far (the server recalculates on send) ─────────────────
    const rates = {
        type: 'dine_in',
        serviceRate: placed ? Number(order.service_charge_rate) : rules.service_charge,
        serviceRemoved: placed ? order.service_charge_removed : false,
        deliveryFee: 0,
        taxRate: placed ? Number(order.tax_rate) : rules.tax_rate,
        rounding: rules.rounding,
    };
    const orderDiscount = placed && order.discount ? { type: order.discount.type, value: Number(order.discount.value) } : null;
    const bill = billOf(
        [
            ...(placed ? order.lines.filter((l) => !l.voided).map((l) => ({ gross: l.gross, discount: l.discount_amount })) : []),
            ...lines.map((l) => ({ gross: lineGross(l, index.get(`${l.type}:${l.id}`)), discount: 0 })),
        ],
        orderDiscount,
        rates,
    );

    // ── cart ──────────────────────────────────────────────────────────
    function addLine(line) {
        setLines((list) => {
            const same = list.find((l) => sameLine(l, line));
            return same ? list.map((l) => (l === same ? { ...l, quantity: Math.min(999, l.quantity + line.quantity) } : l)) : [...list, line];
        });
    }

    function saveLine(line) {
        if (lines.some((l) => l.key === line.key)) setLines(lines.map((l) => (l.key === line.key ? line : l)));
        else addLine(line);
        close();
    }

    function pick(item) {
        if (item.type === 'deal') return setDialog({ kind: 'deal', item });
        if (item.type === 'menu_item' && (item.variants.length || item.groups.length)) return setDialog({ kind: 'item', item });
        addLine({ key: newKey(), type: item.type, id: item.id, name: item.name, variant: null, modifiers: [], picks: [], quantity: 1, notes: '', discount: null });
    }

    function editLine(line) {
        setDialog({ kind: line.type === 'deal' ? 'deal' : 'item', item: index.get(`${line.type}:${line.id}`), line });
    }

    // ── server ────────────────────────────────────────────────────────
    const options = (extra = {}) => ({
        preserveScroll: true,
        preserveState: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        ...extra,
    });

    function send() {
        const data = { items: lines.map(payloadOf), guests: order ? null : guests };
        const done = options({
            onError: (errs) => {
                setErrors(errs);
                setOpen(true);
            },
            onSuccess: () => {
                setLines([]);
                setErrors({});
            },
        });
        if (order) router.put(route('waiter.orders.update', order.id), data, done);
        else router.post(route('waiter.orders.store', table.id), data, done);
    }

    const serve = (uuids) => router.put(route('waiter.orders.serve', order.id), { items: uuids }, options());
    const setStatus = (status) => router.put(route('tables.status', table.id), { status }, options());

    const blocked = order?.is_draft
        ? 'This table’s order is held on the POS — the cashier must send it first.'
        : !shiftOpen
          ? 'No cash counter is open — ask the cashier to open a shift.'
          : null;
    const ready = readyUuids(order);
    const newCount = lines.reduce((n, l) => n + l.quantity, 0);

    return (
        <div className="pos-wrapper waiter-table">
            <PageToolbar
                title={table.area ? `${table.name} · ${table.area}` : table.name}
                headTitle={table.name}
                primary={
                    <Button variant="ghost" icon={ArrowLeft} href={route('waiter.index')}>
                        Tables
                    </Button>
                }
            />

            <div className="waiter-table-bar">
                {order ? (
                    <>
                        <strong className="mono">{order.is_draft ? 'Held' : order.code}</strong>
                        <span>{order.status.label}</span>
                        {order.placed_at && <span>{since(order.placed_at)}</span>}
                        {ready.length > 0 && <span className="is-hot">{ready.length} ready to serve</span>}
                        <span className="waiter-table-total mono">{money(order.grand_total)}</span>
                    </>
                ) : (
                    <>
                        <span>
                            {table.capacity} seats · {table.status.label}
                        </span>
                        {can('tables.status') && table.status.value !== 'occupied' && (
                            <span className="waiter-status-btns">
                                {tableStatuses.map((s) => (
                                    <button
                                        key={s.value}
                                        type="button"
                                        className={cx('pos-cat', table.status.value === s.value && 'active')}
                                        disabled={processing || table.status.value === s.value}
                                        onClick={() => setStatus(s.value)}
                                    >
                                        {s.label}
                                    </button>
                                ))}
                            </span>
                        )}
                    </>
                )}
            </div>

            <div className="pos-grid">
                <ItemPicker items={items} deals={deals} categories={categories} orderType="dine_in" onPick={pick} />

                <WaiterOrder
                    order={order}
                    table={table}
                    lines={lines}
                    index={index}
                    guests={guests}
                    onGuests={setGuests}
                    bill={bill}
                    errors={errors}
                    open={open}
                    processing={processing}
                    blocked={lines.length > 0 ? blocked : null}
                    onClose={() => setOpen(false)}
                    onQty={(key, quantity) => setLines(lines.map((l) => (l.key === key ? { ...l, quantity } : l)))}
                    onEdit={editLine}
                    onRemove={(key) => setLines(lines.filter((l) => l.key !== key))}
                    onSend={send}
                    onServe={serve}
                    onBill={() => setDialog({ kind: 'bill' })}
                    canServe={can('waiter.orders.serve')}
                    canBill={can('waiter.orders.bill')}
                />
            </div>

            <button type="button" className={cx('pos-cart-bar', ready.length > 0 && newCount === 0 && 'is-ready')} onClick={() => setOpen(true)}>
                <ClipboardList size={16} strokeWidth={1.5} />
                <span>
                    {newCount > 0
                        ? `${newCount} new · ${money(bill.grand)}`
                        : ready.length > 0
                          ? `${ready.length} ready to serve`
                          : order
                            ? `${order.is_draft ? 'Held' : order.code} · ${money(order.grand_total)}`
                            : 'New order'}
                </span>
                <span className="pos-cart-bar-go">{newCount > 0 ? 'Review & send' : 'View order'}</span>
            </button>

            {dialog?.kind === 'item' && <ItemDialog item={dialog.item} line={dialog.line} onSave={saveLine} onClose={close} />}
            {dialog?.kind === 'deal' && <DealDialog deal={dialog.item} line={dialog.line} onSave={saveLine} onClose={close} />}
            <ConfirmDialog
                open={dialog?.kind === 'bill'}
                onClose={close}
                title={`Ask for the bill of ${table.name}?`}
                message={order ? `The cashier is told and the bill of ${money(order.due)} prints at the counter.` : ''}
                confirmLabel="Ask for Bill"
                processing={processing}
                onConfirm={() => router.post(route('waiter.orders.bill', order.id), {}, options({ onSuccess: close }))}
            />
        </div>
    );
}
