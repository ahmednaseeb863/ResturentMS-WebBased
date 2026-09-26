import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ClipboardList, Clock, LogOut, Plus, Printer, ShoppingCart } from 'lucide-react';
import { Button, ConfirmDialog, PageStatus, PageToolbar } from '@/components/ui';
import Cart from '@/components/pos/Cart';
import CustomerDialog from '@/components/pos/CustomerDialog';
import DealDialog from '@/components/pos/DealDialog';
import DiscountDialog from '@/components/pos/DiscountDialog';
import ItemDialog from '@/components/pos/ItemDialog';
import ItemPicker from '@/components/pos/ItemPicker';
import OpenOrdersDrawer from '@/components/pos/OpenOrdersDrawer';
import ReadyAlerts from '@/components/pos/ReadyAlerts';
import TableDialog from '@/components/pos/TableDialog';
import { fromHeld, lineDiscount, lineGross, newKey, payloadOf, sameLine } from '@/components/pos/cartLines';
import PinDialog from '@/components/orders/PinDialog';
import VoidDialog from '@/components/orders/VoidDialog';
import PrintDeviceDialog from '@/components/printing/PrintDeviceDialog';
import useCan from '@/hooks/useCan';
import { money } from '@/lib/format';
import { bill as billOf } from '@/lib/pricing';

const TYPE_LABELS = { dine_in: 'Dine-in', takeaway: 'Takeaway', delivery: 'Delivery' };

/**
 * Cashier POS (pos-react Point of Sale): items on the left, the cart on the right.
 * A new cart lives here until it is held or sent; `?order=` opens a held / open order.
 */
export default function PosIndex() {
    const { order } = usePage().props;
    const [nonce, setNonce] = useState(0);

    // a fresh screen (and state) per order, and after every successful save
    return <PosScreen key={`${order?.id ?? 'new'}-${nonce}`} onSaved={() => setNonce((n) => n + 1)} />;
}

function initialState(order, index, discounts, types) {
    const saved = order?.delivery?.saved_address ?? null;
    const preset = order?.discount?.preset ? discounts.find((d) => d.id === order.discount.preset) : null;

    return {
        type: order?.type.value ?? types[0] ?? 'takeaway',
        table: order?.table?.id ?? null,
        waiter: order?.waiter?.id ?? null,
        guests: order?.guests ?? null,
        customer: order?.customer ?? null,
        address: saved,
        addressText: order?.delivery && !saved ? order.delivery.address : '',
        notes: order?.notes ?? '',
        discount: order?.discount
            ? {
                  discount: order.discount.preset,
                  type: order.discount.type,
                  value: Number(order.discount.value),
                  reason: order.discount.reason,
                  name: order.discount.name,
                  max: preset?.max_amount ?? null,
                  min: preset?.min_amount ?? null,
              }
            : null,
        removeService: order?.service_charge_removed ?? false,
        lines: order?.is_draft ? fromHeld(order.held_items, index, discounts) : [],
    };
}

function PosScreen({ onSaved }) {
    const { order, items, deals, categories, tables, waiters, discounts, rules, openOrders, printers, context } = usePage().props;
    const can = useCan();
    const index = useMemo(() => new Map([...items, ...deals].map((i) => [i.key, i])), [items, deals]);
    const types = rules.types.map((t) => ({ value: t, label: TYPE_LABELS[t] }));

    const [start] = useState(() => initialState(order, index, discounts, rules.types));
    const [state, setState] = useState(start);
    const [dialog, setDialog] = useState(null); // { kind, ... }
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [cartOpen, setCartOpen] = useState(false);

    const set = (patch) => setState((s) => ({ ...s, ...patch }));
    const close = () => setDialog(null);
    const placed = order && !order.is_draft;
    const sent = placed ? order.lines : [];

    // ── bill ──────────────────────────────────────────────────────────
    const rates = {
        type: state.type,
        serviceRate: placed ? Number(order.service_charge_rate) : state.type === 'dine_in' ? rules.service_charge : 0,
        serviceRemoved: state.removeService,
        deliveryFee: placed ? Number(order.delivery_fee) : rules.delivery_fee,
        taxRate: placed ? Number(order.tax_rate) : rules.tax_rate,
        taxName: placed ? order.tax_name : rules.tax_name,
        rounding: rules.rounding,
    };
    const billLines = [
        ...sent.filter((l) => !l.voided).map((l) => ({ gross: l.gross, discount: l.discount_amount })),
        ...state.lines.map((l) => {
            const item = index.get(`${l.type}:${l.id}`);
            return { gross: lineGross(l, item), discount: lineDiscount(l, item) };
        }),
    ];
    const bill = billOf(billLines, state.discount, rates);

    // ── details shown on the chips ────────────────────────────────────
    const table = tables.find((t) => t.id === state.table);
    const waiter = waiters.find((w) => w.value === state.waiter);
    const savedAddress = state.customer?.addresses?.find((a) => a.id === state.address);
    const addressLine = savedAddress
        ? [savedAddress.address, savedAddress.area].filter(Boolean).join(', ')
        : state.address && order?.delivery
          ? order.delivery.address
          : state.addressText;
    const details = {
        table: state.table,
        tableText: [table?.name ?? order?.table?.name, state.guests && `${state.guests} guests`, waiter?.label].filter(Boolean).join(' · '),
        customer: state.customer,
        customerText: state.customer
            ? `${state.customer.name}${state.type === 'delivery' && addressLine ? ` — ${addressLine}` : ` · ${state.customer.phone ?? ''}`}`
            : '',
    };

    // ── cart edits ────────────────────────────────────────────────────
    function addLine(line) {
        setState((s) => {
            const same = s.lines.find((l) => sameLine(l, line));
            return same
                ? { ...s, lines: s.lines.map((l) => (l === same ? { ...l, quantity: Math.min(999, l.quantity + line.quantity) } : l)) }
                : { ...s, lines: [...s.lines, line] };
        });
    }

    function saveLine(line) {
        if (state.lines.some((l) => l.key === line.key)) set({ lines: state.lines.map((l) => (l.key === line.key ? line : l)) });
        else addLine(line);
        close();
    }

    function pick(item) {
        if (item.type === 'deal') return setDialog({ kind: 'deal', item });
        if (item.type === 'menu_item' && (item.variants.length || item.groups.length)) return setDialog({ kind: 'item', item });
        addLine({ key: newKey(), type: item.type, id: item.id, name: item.name, variant: null, modifiers: [], picks: [], quantity: 1, notes: '', discount: null });
    }

    function editLine(line) {
        const item = index.get(`${line.type}:${line.id}`);
        setDialog({ kind: line.type === 'deal' ? 'deal' : 'item', item, line });
    }

    const setQty = (key, quantity) => set({ lines: state.lines.map((l) => (l.key === key ? { ...l, quantity } : l)) });
    const removeLine = (key) => set({ lines: state.lines.filter((l) => l.key !== key) });

    // ── saving ────────────────────────────────────────────────────────
    function payload(action, pin) {
        return {
            action,
            type: state.type,
            table: state.type === 'dine_in' ? state.table : null,
            waiter: state.type === 'dine_in' ? state.waiter : null,
            guests: state.type === 'dine_in' ? state.guests : null,
            customer: state.customer?.id ?? null,
            address: state.type === 'delivery' ? state.address : null,
            address_text: state.type === 'delivery' && !state.address ? state.addressText : null,
            notes: state.notes || null,
            items: state.lines.map(payloadOf),
            discount: state.discount
                ? { discount: state.discount.discount, type: state.discount.type, value: state.discount.value, reason: state.discount.reason }
                : null,
            remove_service_charge: state.type === 'dine_in' && state.removeService,
            pin: pin ?? null,
        };
    }

    function save(action, pin) {
        const options = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errs) => {
                setErrors(errs);
                if (errs.pin) setDialog({ kind: 'pin', action, message: pinMessage(), error: pin ? errs.pin : null });
                else setCartOpen(true);
            },
            onSuccess: () => {
                setDialog(null);
                onSaved();
            },
        };
        const data = payload(action, pin);
        if (order) router.put(route('pos.orders.update', order.id), data, options);
        else router.post(route('pos.orders.store'), data, options);
    }

    function pinMessage() {
        const what = [
            (state.discount || state.lines.some((l) => l.discount)) && 'the discount',
            state.removeService && !order?.service_charge_removed && 'removing the service charge',
        ].filter(Boolean);
        return `A manager must approve ${what.join(' and ') || 'this'} with their PIN.`;
    }

    const detailsDirty =
        JSON.stringify({ ...state, lines: null, customer: state.customer?.id }) !== JSON.stringify({ ...start, lines: null, customer: start.customer?.id });

    // ── screen ────────────────────────────────────────────────────────
    const count = sent.filter((l) => !l.voided).reduce((n, l) => n + l.quantity, 0) + state.lines.reduce((n, l) => n + l.quantity, 0);

    return (
        <div className="pos-wrapper">
            <PageToolbar
                title={order ? `Point of Sale — ${order.is_draft ? 'Held Order' : order.code}` : 'Point of Sale'}
                headTitle="Point of Sale"
                primary={
                    <Button variant="danger" icon={LogOut} href={route('dashboard')}>
                        Exit POS
                    </Button>
                }
            >
                <Button icon={ClipboardList} onClick={() => setDialog({ kind: 'orders' })}>
                    Open Orders ({openOrders.length})
                </Button>
                {(order || state.lines.length > 0) && (
                    <Button icon={Plus} onClick={() => (order ? router.visit(route('pos.index')) : onSaved())}>
                        New Order
                    </Button>
                )}
                {can('orders.index') && (
                    <Button variant="ghost" href={route('orders.index')}>
                        All Orders
                    </Button>
                )}
                {can('print-jobs.pending') && (
                    <Button
                        variant="ghost"
                        icon={Printer}
                        onClick={() =>
                            router.reload({ only: ['printers'], preserveUrl: true, onSuccess: () => setDialog({ kind: 'printing' }) })
                        }
                    >
                        Printing
                    </Button>
                )}
            </PageToolbar>
            <ReadyAlerts />
            <PageStatus>
                <span>{order ? `${order.is_draft ? 'Held' : order.code} · ${order.label}` : 'New order'}</span>
            </PageStatus>

            {!context.shift && (
                <div className="pos-no-shift">
                    <Clock size={14} strokeWidth={1.5} />
                    <span>Your shift is not open — open it on a cash counter to take orders.</span>
                    {can('shifts.index') && (
                        <Button variant="primary" href={route('shifts.index')}>
                            Open Shift
                        </Button>
                    )}
                </div>
            )}

            <div className="pos-grid">
                <ItemPicker items={items} deals={deals} categories={categories} orderType={state.type} onPick={pick} />

                <Cart
                    order={order}
                    types={types}
                    type={state.type}
                    onType={(type) => set({ type })}
                    details={details}
                    sent={sent}
                    lines={state.lines}
                    index={index}
                    errors={errors}
                    bill={bill}
                    rates={rates}
                    orderDiscount={state.discount}
                    can={{
                        hold: rules.hold,
                        void: can('orders.items.void'),
                        discount: can('orders.discount'),
                        serviceCharge: can('orders.service-charge') && rules.service_removable,
                    }}
                    open={cartOpen}
                    processing={processing}
                    onClose={() => setCartOpen(false)}
                    onDetails={(kind) => setDialog({ kind })}
                    onQty={setQty}
                    onEdit={editLine}
                    onRemove={removeLine}
                    onLineDiscount={(line) => setDialog({ kind: 'line-discount', line })}
                    onVoid={(line) => setDialog({ kind: 'void', line })}
                    onOrderDiscount={() => setDialog({ kind: 'order-discount' })}
                    onServiceCharge={() => set({ removeService: !state.removeService })}
                    onHold={() => save('hold')}
                    onSend={() => save(placed && state.lines.length === 0 ? 'save' : 'send')}
                    onDiscard={() => setDialog({ kind: 'discard' })}
                    saveLabel={placed && state.lines.length === 0 && detailsDirty ? 'Save Changes' : null}
                />
            </div>

            <button type="button" className="pos-cart-bar" onClick={() => setCartOpen(true)}>
                <ShoppingCart size={16} strokeWidth={1.5} />
                <span>
                    {count} item{count === 1 ? '' : 's'} · {money(bill.grand)}
                </span>
                <span className="pos-cart-bar-go">View cart</span>
            </button>

            {dialog?.kind === 'item' && <ItemDialog item={dialog.item} line={dialog.line} onSave={saveLine} onClose={close} />}
            {dialog?.kind === 'deal' && <DealDialog deal={dialog.item} line={dialog.line} onSave={saveLine} onClose={close} />}
            {dialog?.kind === 'table' && (
                <TableDialog
                    tables={tables}
                    waiters={waiters}
                    orderId={order?.id}
                    value={{ table: state.table, waiter: state.waiter, guests: state.guests }}
                    onSave={(v) => {
                        set(v);
                        close();
                    }}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'customer' && (
                <CustomerDialog
                    value={{ customer: state.customer, address: state.address, addressText: state.addressText }}
                    delivery={state.type === 'delivery'}
                    onSave={(v) => {
                        set(v);
                        close();
                    }}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'order-discount' && (
                <DiscountDialog
                    title="Discount on the order"
                    scope="order"
                    presets={discounts}
                    value={state.discount}
                    base={bill.items - bill.lineDiscounts}
                    onSave={(discount) => {
                        set({ discount });
                        close();
                    }}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'line-discount' && (
                <DiscountDialog
                    title={`Discount — ${dialog.line.name}`}
                    scope="item"
                    presets={discounts}
                    value={dialog.line.discount}
                    base={lineGross(dialog.line, index.get(`${dialog.line.type}:${dialog.line.id}`))}
                    onSave={(discount) => {
                        set({ lines: state.lines.map((l) => (l.key === dialog.line.key ? { ...l, discount } : l)) });
                        close();
                    }}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'pin' && (
                <PinDialog message={dialog.message} error={dialog.error} processing={processing} onSubmit={(pin) => save(dialog.action, pin)} onClose={close} />
            )}
            {dialog?.kind === 'void' && <VoidDialog order={order} line={dialog.line} pinRequired={rules.pin_void} onClose={close} />}
            {dialog?.kind === 'orders' && <OpenOrdersDrawer orders={openOrders} currentId={order?.id} onClose={close} />}
            {dialog?.kind === 'printing' && <PrintDeviceDialog printers={printers ?? []} onClose={close} />}
            <ConfirmDialog
                open={dialog?.kind === 'discard'}
                onClose={close}
                title="Discard held order?"
                message="The held items are thrown away. The order is kept as cancelled."
                confirmLabel="Discard"
                danger
                processing={processing}
                onConfirm={() =>
                    router.put(route('pos.orders.discard', order.id), {}, {
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                        onSuccess: onSaved,
                    })
                }
            />
        </div>
    );
}
