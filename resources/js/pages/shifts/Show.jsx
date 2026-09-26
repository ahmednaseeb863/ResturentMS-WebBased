import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { ArrowDownUp, LogOut, Printer, RotateCcw, UserPlus, X, XCircle } from 'lucide-react';
import {
    Button,
    ChartBox,
    DataTable,
    Dialog,
    Field,
    FormGrid,
    InfoCards,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    Tag,
} from '@/components/ui';
import CloseShiftDialog, { DiffBanner, DrawerSummary } from '@/components/shifts/CloseShiftDialog';
import ShiftBanner from '@/components/shifts/ShiftBanner';
import StaffPicker from '@/components/shifts/StaffPicker';
import useCan from '@/hooks/useCan';
import { cx, date, dateTime, money, number } from '@/lib/format';

const TYPE_HINTS = {
    cash_in: 'Cash put into the drawer, e.g. change from the bank',
    cash_out: 'Cash taken out and spent or given, e.g. ice, tips',
    safe_drop: 'Extra cash moved from the drawer to the safe',
};

function CashMovementDialog({ shift, types, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ type: types[0].value, amount: '', reason: '' });

    function submit(e) {
        e.preventDefault();
        post(route('shifts.cash', shift.id), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Cash In / Out — ${shift.code}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="cash-form" disabled={processing}>
                        {processing ? 'Saving…' : 'Record'}
                    </Button>
                </>
            }
        >
            <form id="cash-form" onSubmit={submit} noValidate>
                <div className="cash-type-buttons" role="radiogroup" aria-label="Type">
                    {types.map((t) => (
                        <button
                            key={t.value}
                            type="button"
                            role="radio"
                            aria-checked={data.type === t.value}
                            className={cx('cash-type-btn', `ct-${t.value}`, data.type === t.value && 'on')}
                            onClick={() => setData('type', t.value)}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
                <p className="field-hint cash-type-hint">{TYPE_HINTS[data.type]}</p>
                <FormGrid>
                    <Field label="Amount" required full error={errors.amount ?? errors.type}>
                        <Input
                            mono
                            type="number"
                            min="1"
                            step="0.01"
                            inputMode="decimal"
                            value={data.amount}
                            invalid={errors.amount}
                            onChange={(e) => setData('amount', e.target.value)}
                            autoFocus
                        />
                    </Field>
                    <Field label="Reason" required={data.type !== 'safe_drop'} full error={errors.reason}>
                        <Input value={data.reason} invalid={errors.reason} maxLength={255} onChange={(e) => setData('reason', e.target.value)} />
                    </Field>
                </FormGrid>
            </form>
        </Dialog>
    );
}

function ReopenDialog({ shift, pinRequired, onClose }) {
    const { data, setData, put, processing, errors } = useForm({ reason: '', pin: '' });

    function submit(e) {
        e.preventDefault();
        put(route('shifts.reopen', shift.id), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Reopen Shift — ${shift.code}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="reopen-form" disabled={processing}>
                        {processing ? 'Reopening…' : 'Reopen Shift'}
                    </Button>
                </>
            }
        >
            <form id="reopen-form" onSubmit={submit} noValidate>
                <p className="ui-dialog-text">
                    The count of {money(shift.counted_cash)} is cleared and the shift is open again for {shift.opened_by?.name}. The
                    business day stays {date(shift.business_date)}. This is logged.
                </p>
                <FormGrid>
                    <Field label="Reason" required full error={errors.reason}>
                        <Input value={data.reason} invalid={errors.reason} maxLength={255} onChange={(e) => setData('reason', e.target.value)} autoFocus />
                    </Field>
                    {pinRequired && (
                        <Field label="Manager PIN" required full error={errors.pin}>
                            <Input
                                mono
                                type="password"
                                inputMode="numeric"
                                autoComplete="off"
                                maxLength={6}
                                value={data.pin}
                                invalid={errors.pin}
                                onChange={(e) => setData('pin', e.target.value.replace(/\D/g, ''))}
                            />
                        </Field>
                    )}
                </FormGrid>
            </form>
        </Dialog>
    );
}

function StaffPanel({ shift, staff, options, canEdit }) {
    const [adding, setAdding] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ staff: [] });
    const onDuty = staff.filter((s) => s.on_duty).map((s) => s.employee?.id);
    const free = options.filter((o) => !onDuty.includes(o.value));

    function add() {
        post(route('shifts.staff.store', shift.id), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setAdding(false);
            },
        });
    }

    const act = (method, name, row) => router[method](route(name, [shift.id, row.id]), {}, { preserveScroll: true });

    return (
        <ChartBox
            title="Staff on Duty"
            actions={
                canEdit &&
                free.length > 0 &&
                !adding && (
                    <Button variant="ghost" icon={UserPlus} onClick={() => setAdding(true)}>
                        Check In
                    </Button>
                )
            }
        >
            {staff.length === 0 && !adding && <div className="cell-muted shift-panel-empty">Nobody checked in.</div>}
            <div className="shift-staff-list">
                {staff.map((s) => (
                    <div key={s.id} className={cx('shift-staff-row', !s.on_duty && 'is-out')}>
                        <div>
                            <div className="cell-strong">{s.employee?.name}</div>
                            <div className="cell-sub">
                                {s.employee?.designation && `${s.employee.designation} · `}
                                {dateTime(s.checked_in_at)} – {s.checked_out_at ? dateTime(s.checked_out_at) : 'on duty'}
                            </div>
                        </div>
                        {canEdit && s.on_duty && (
                            <div className="shift-staff-actions">
                                <Button variant="ghost" icon={LogOut} onClick={() => act('put', 'shifts.staff.checkout', s)}>
                                    Out
                                </Button>
                                <button
                                    type="button"
                                    className="cust-close"
                                    aria-label={`Remove ${s.employee?.name} (added by mistake)`}
                                    title="Added by mistake"
                                    onClick={() => act('delete', 'shifts.staff.destroy', s)}
                                >
                                    <X size={14} strokeWidth={1.5} />
                                </button>
                            </div>
                        )}
                    </div>
                ))}
            </div>
            {adding && (
                <div className="shift-staff-add">
                    <StaffPicker options={free} value={data.staff} onChange={(v) => setData('staff', v)} />
                    {errors.staff && <div className="field-error">{errors.staff}</div>}
                    <div className="shift-staff-add-actions">
                        <Button variant="ghost" onClick={() => setAdding(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" onClick={add} disabled={processing || !data.staff.length}>
                            Check In
                        </Button>
                    </div>
                </div>
            )}
        </ChartBox>
    );
}

function CountTable({ title, lines }) {
    if (!lines.length) return null;
    const total = lines.reduce((s, l) => s + Number(l.amount), 0);

    return (
        <ChartBox title={title}>
            <div className="shift-close-summary">
                {lines.map((l) => (
                    <div key={l.denomination} className="shift-close-row">
                        <span className="mono">
                            {number(l.denomination)} × {l.quantity}
                        </span>
                        <span className="mono">{number(l.amount)}</span>
                    </div>
                ))}
                <div className="shift-close-row shift-close-expected">
                    <span>Total</span>
                    <span className="mono">{money(total)}</span>
                </div>
            </div>
        </ChartBox>
    );
}

/** One shift: drawer, cash in / out, staff, close / reopen, X / Z report. */
export default function ShiftShow({ shift, lines, transfers, movements, counts, staff, staffOptions, movementTypes, denominations, canHandle, canReopen, rules }) {
    const can = useCan();
    const { url } = usePage();
    const open = shift.is_open;
    const handle = open && canHandle;
    const [dialog, setDialog] = useState(() => (open && handle && url.includes('close=1') ? 'close' : null));

    const movementColumns = [
        { key: 'when', label: 'When', className: 'mono', render: (r) => dateTime(r.created_at) },
        { key: 'type', label: 'Type', render: (r) => <Tag tone={r.type.tone}>{r.type.label}</Tag> },
        {
            key: 'in',
            label: 'In',
            align: 'right',
            className: 'mono ledger-in',
            render: (r) => (r.direction > 0 ? `+${number(r.amount)}` : ''),
        },
        {
            key: 'out',
            label: 'Out',
            align: 'right',
            className: 'mono ledger-out',
            render: (r) => (r.direction < 0 ? `−${number(r.amount)}` : ''),
        },
        { key: 'reason', label: 'Reason', render: (r) => r.reason ?? <span className="cell-muted">—</span> },
        { key: 'admin', label: 'By', render: (r) => r.admin ?? <span className="cell-muted">System</span> },
    ];

    const primary = handle
        ? can('shifts.close') && (
              <Button variant="danger" icon={XCircle} onClick={() => setDialog('close')}>
                  Close Shift
              </Button>
          )
        : !open &&
          canReopen &&
          can('shifts.reopen') && (
              <Button variant="primary" icon={RotateCcw} onClick={() => setDialog('reopen')}>
                  Reopen Shift
              </Button>
          );

    const expected = Number(shift.expected_cash ?? 0);

    return (
        <PageBody>
            <PageToolbar title={`Shift ${shift.code}`} primary={primary}>
                {handle && can('shifts.cash') && (
                    <Button icon={ArrowDownUp} onClick={() => setDialog('cash')}>
                        Cash In / Out
                    </Button>
                )}
                {can('shifts.report') && (
                    <a className="btn btn-secondary" href={route('shifts.report', shift.id)} target="_blank" rel="noreferrer">
                        <Printer strokeWidth={1.5} />
                        {open ? 'X-Report' : 'Z-Report'}
                    </a>
                )}
                <Button variant="ghost" href={route('shifts.index')}>
                    All Shifts
                </Button>
            </PageToolbar>
            <PageStatus>
                <span>
                    {shift.counter?.name} · {shift.opened_by?.name} · business day {date(shift.business_date)}
                </span>
                {shift.reopen_count > 0 && (
                    <span>
                        Reopened {shift.reopen_count}× — last by {shift.reopened_by?.name}, {dateTime(shift.reopened_at)}
                    </span>
                )}
            </PageStatus>

            {open ? (
                <ShiftBanner shift={shift} />
            ) : (
                <InfoCards
                    items={[
                        { label: 'Business Day', value: date(shift.business_date) },
                        { label: 'Expected Cash', value: money(shift.expected_cash), className: 'mono' },
                        { label: 'Counted Cash', value: money(shift.counted_cash), className: 'mono' },
                        {
                            label: 'Difference',
                            value:
                                Number(shift.difference) === 0
                                    ? 'Balanced'
                                    : `${Number(shift.difference) > 0 ? 'Over' : 'Short'} ${money(Math.abs(shift.difference))}`,
                            className: cx('mono', Number(shift.difference) > 0 && 'cash-plus', Number(shift.difference) < 0 && 'cash-minus'),
                        },
                        { label: 'Float Left', value: money(shift.float_left), className: 'mono' },
                        { label: 'Handed Over', value: money(shift.handed_over_amount), className: 'mono' },
                    ]}
                />
            )}

            <div className="dash-row shift-detail-row">
                <ChartBox title="Cash Drawer">
                    {shift.cash_visible ? (
                        <>
                            <DrawerSummary lines={lines} expected={expected} />
                            {!open && (
                                <div className="shift-drawer-result">
                                    <div className="shift-close-row">
                                        <span>Counted cash</span>
                                        <span className="mono">{money(shift.counted_cash)}</span>
                                    </div>
                                    <DiffBanner diff={Number(shift.difference)} />
                                </div>
                            )}
                        </>
                    ) : (
                        <div className="cell-muted shift-panel-empty">Blind close is on — the expected cash is shown after closing.</div>
                    )}
                    {transfers.length > 0 && (
                        <div className="shift-close-summary shift-transfers">
                            {transfers.map((t) => (
                                <div key={t.account} className="shift-close-row">
                                    <span>
                                        Bank transfers · {t.account}
                                        <span className="cell-muted"> ({t.count})</span>
                                    </span>
                                    <span className="mono">{money(t.total)}</span>
                                </div>
                            ))}
                        </div>
                    )}
                    <dl className="shift-facts">
                        <dt>Opened</dt>
                        <dd>
                            {dateTime(shift.opened_at)} by {shift.opened_by?.name}
                        </dd>
                        {shift.type && (
                            <>
                                <dt>Shift type</dt>
                                <dd>
                                    {shift.type.name} · {shift.type.hours}
                                </dd>
                            </>
                        )}
                        {shift.notes && (
                            <>
                                <dt>Notes</dt>
                                <dd>{shift.notes}</dd>
                            </>
                        )}
                        {!open && (
                            <>
                                <dt>Closed</dt>
                                <dd>
                                    {dateTime(shift.closed_at)} by {shift.closed_by?.name}
                                </dd>
                            </>
                        )}
                        {shift.approved_by && (
                            <>
                                <dt>Difference approved by</dt>
                                <dd>{shift.approved_by.name}</dd>
                            </>
                        )}
                        {shift.closing_notes && (
                            <>
                                <dt>Closing notes</dt>
                                <dd>{shift.closing_notes}</dd>
                            </>
                        )}
                    </dl>
                </ChartBox>
                <StaffPanel shift={shift} staff={staff} options={staffOptions} canEdit={handle && can('shifts.staff.store')} />
            </div>

            <div className="section-title">Cash In / Out</div>
            <DataTable columns={movementColumns} rows={movements} noun="entries" empty="No cash in or out yet" stack />

            {(counts.opening.length > 0 || counts.closing.length > 0) && (
                <div className="dash-row shift-count-row">
                    <CountTable title="Closing Count" lines={counts.closing} />
                    <CountTable title="Opening Count" lines={counts.opening} />
                </div>
            )}

            {dialog === 'close' && (
                <CloseShiftDialog shift={shift} lines={lines} denominations={denominations} rules={rules} onClose={() => setDialog(null)} />
            )}
            {dialog === 'cash' && <CashMovementDialog shift={shift} types={movementTypes} onClose={() => setDialog(null)} />}
            {dialog === 'reopen' && <ReopenDialog shift={shift} pinRequired={rules.pin_reopen} onClose={() => setDialog(null)} />}
        </PageBody>
    );
}
