import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { CalendarCheck, ChevronLeft, ChevronRight, Clock, Phone, Plus, StickyNote, Users, UtensilsCrossed } from 'lucide-react';
import {
    Button,
    ChartBox,
    DataTable,
    Dialog,
    Drawer,
    Field,
    FilterBar,
    FilterSelect,
    FormGrid,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    Select,
    Tabs,
    Tag,
    Textarea,
    Toggle,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import { clock, cx, date } from '@/lib/format';

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function pad(n) {
    return String(n).padStart(2, '0');
}

/** Cells of a month grid (Monday first): { date: 'yyyy-mm-dd', inMonth }. */
function monthCells(month) {
    const [y, m] = month.split('-').map(Number);
    const first = new Date(y, m - 1, 1);
    const offset = (first.getDay() + 6) % 7;
    const cells = [];
    for (let i = 0; i < 42; i++) {
        const d = new Date(y, m - 1, 1 - offset + i);
        cells.push({ date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, day: d.getDate(), inMonth: d.getMonth() === m - 1 });
    }
    // drop a last row that is entirely next month
    while (cells.length > 35 && cells.slice(-7).every((c) => !c.inMonth)) cells.splice(-7);
    return cells;
}

function otherMonth(month, delta) {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
}

function ReasonDialog({ reservation, onClose, onDone }) {
    const { data, setData, put, processing, errors } = useForm({ action: 'cancel', reason: '' });

    function submit(e) {
        e.preventDefault();
        put(route('reservations.status', reservation.id), { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Cancel ${reservation.code} — ${reservation.guest_name}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Keep it
                    </Button>
                    <Button variant="danger" type="submit" form="rsv-cancel-form" disabled={processing}>
                        {processing ? 'Cancelling…' : 'Cancel Reservation'}
                    </Button>
                </>
            }
        >
            <form id="rsv-cancel-form" onSubmit={submit} noValidate>
                <Field label="Reason" required error={errors.reason || errors.reservation}>
                    <Input value={data.reason} invalid={errors.reason} onChange={(e) => setData('reason', e.target.value)} placeholder="e.g. Guest called to cancel" autoFocus />
                </Field>
            </form>
        </Dialog>
    );
}

function ReservationDrawer({ reservation, tables, defaultMinutes, day, now, today, onClose, onCancel }) {
    const can = useCan();
    const isEdit = Boolean(reservation?.id);
    const editable = !isEdit || (reservation.upcoming && can('reservations.update'));
    const { data, setData, post, put, processing, errors } = useForm({
        guest_name: reservation?.guest_name ?? '',
        guest_phone: reservation?.guest_phone ?? '',
        party_size: reservation?.party_size ?? 2,
        date: reservation?.date ?? (day < today ? today : day),
        time: reservation?.time ?? (day === today ? `${pad(Math.min(23, Number(now.slice(0, 2)) + 1))}:00` : '19:00'),
        duration_minutes: reservation?.duration_minutes ?? defaultMinutes,
        table: reservation?.table?.id ?? '',
        notes: reservation?.notes ?? '',
        confirmed: reservation ? reservation.status.value !== 'pending' : true,
    });

    function submit() {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('reservations.update', reservation.id), options);
        else post(route('reservations.store'), options);
    }

    function act(action) {
        router.put(route('reservations.status', reservation.id), { action }, { preserveScroll: true, onSuccess: onClose });
    }

    const status = reservation?.status;
    const tableOptions = [{ value: '', label: 'No table yet' }, ...tables];

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={editable ? submit : undefined}
            title={isEdit ? `${reservation.code} — ${reservation.guest_name}` : 'New Reservation'}
            footer={
                <>
                    {isEdit && reservation.upcoming && can('reservations.status') && (
                        <div className="footer-start rsv-actions">
                            {status.value === 'pending' && (
                                <Button onClick={() => act('confirm')} disabled={processing}>
                                    Confirm
                                </Button>
                            )}
                            <Button onClick={() => act('seat')} disabled={processing}>
                                Seated
                            </Button>
                            {(reservation.date < today || (reservation.date === today && reservation.time <= now)) && (
                                <Button variant="ghost" onClick={() => act('no_show')} disabled={processing}>
                                    No-show
                                </Button>
                            )}
                            <Button variant="ghost" className="text-danger" onClick={onCancel} disabled={processing}>
                                Cancel booking
                            </Button>
                        </div>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Close
                    </Button>
                    {editable && (
                        <Button variant="primary" type="submit" disabled={processing}>
                            {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Book Table'}
                        </Button>
                    )}
                </>
            }
        >
            {isEdit && (
                <div className="rsv-drawer-status">
                    <Tag tone={status.tone}>{status.label}</Tag>
                    <span>
                        Booked by {reservation.created_by?.name ?? '—'}
                        {reservation.close_reason && ` · ${reservation.close_reason}`}
                    </span>
                </div>
            )}
            {errors.reservation && <div className="field-error">{errors.reservation}</div>}
            <FormGrid>
                <Field label="Guest name" required error={errors.guest_name}>
                    <Input value={data.guest_name} disabled={!editable} invalid={errors.guest_name} onChange={(e) => setData('guest_name', e.target.value)} autoFocus={!isEdit} />
                </Field>
                <Field label="Phone" error={errors.guest_phone} hint="Links a customer with this number">
                    <Input mono type="tel" value={data.guest_phone} disabled={!editable} onChange={(e) => setData('guest_phone', e.target.value)} />
                </Field>
                <Field label="Date" required error={errors.date}>
                    <Input type="date" value={data.date} min={isEdit ? undefined : today} disabled={!editable} invalid={errors.date} onChange={(e) => setData('date', e.target.value)} />
                </Field>
                <Field label="Time" required error={errors.time}>
                    <Input type="time" value={data.time} disabled={!editable} invalid={errors.time} onChange={(e) => setData('time', e.target.value)} />
                </Field>
                <Field label="Guests" required error={errors.party_size}>
                    <Input mono type="number" min="1" max="500" inputMode="numeric" value={data.party_size} disabled={!editable} invalid={errors.party_size} onChange={(e) => setData('party_size', e.target.value)} />
                </Field>
                <Field label="Holds the table for" error={errors.duration_minutes} hint="minutes">
                    <Input mono type="number" min="15" max="600" step="15" value={data.duration_minutes} disabled={!editable} onChange={(e) => setData('duration_minutes', e.target.value)} />
                </Field>
                <Field label="Table" full error={errors.table}>
                    <Select value={data.table} options={tableOptions} disabled={!editable} invalid={errors.table} onChange={(e) => setData('table', e.target.value)} />
                </Field>
                <Field label="Notes" full error={errors.notes}>
                    <Textarea value={data.notes} disabled={!editable} rows={3} placeholder="Birthday, high chair, window seat…" onChange={(e) => setData('notes', e.target.value)} />
                </Field>
                {editable && (!isEdit || status.value === 'pending') && (
                    <Field label="Status" full>
                        <div className="field-inline">
                            <span className="field-inline-label">{data.confirmed ? 'Confirmed with the guest' : 'Pending — call back to confirm'}</span>
                            <Toggle checked={data.confirmed} onChange={(v) => setData('confirmed', v)} label="Confirmed" />
                        </div>
                    </Field>
                )}
            </FormGrid>
        </Drawer>
    );
}

function DayList({ reservations, onOpen, empty }) {
    if (!reservations.length) return <div className="dash-empty">{empty}</div>;

    return (
        <div className="rsv-day-list">
            {reservations.map((r) => (
                <button key={r.id} type="button" className={cx('rsv-card', !r.upcoming && r.status.value !== 'seated' && 'is-closed')} onClick={() => onOpen(r)}>
                    <div className="rsv-card-time mono">
                        {r.time}
                        <span>{r.ends}</span>
                    </div>
                    <div className="rsv-card-main">
                        <div className="rsv-card-name">{r.guest_name}</div>
                        <div className="rsv-card-meta">
                            <span>
                                <Users size={11} strokeWidth={1.5} /> {r.party_size}
                            </span>
                            {r.table && (
                                <span>
                                    <UtensilsCrossed size={11} strokeWidth={1.5} /> {r.table.name}
                                </span>
                            )}
                            {r.guest_phone && (
                                <span className="mono">
                                    <Phone size={11} strokeWidth={1.5} /> {r.guest_phone}
                                </span>
                            )}
                            {r.notes && (
                                <span>
                                    <StickyNote size={11} strokeWidth={1.5} /> {r.notes}
                                </span>
                            )}
                        </div>
                    </div>
                    <Tag tone={r.status.tone}>{r.status.label}</Tag>
                </button>
            ))}
        </div>
    );
}

/** Reservations (PLAN §4.17): month calendar + the chosen day's bookings, or a searchable list. */
export default function ReservationsIndex({ filters, today, now, tables, statuses, defaultMinutes, counts, month, day, list }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const [cancelling, setCancelling] = useState(null);
    const isList = filters.view === 'list';

    const visit = (params) => router.get(route('reservations.index'), params, { preserveScroll: true, preserveState: true, replace: true });

    const listColumns = [
        { key: 'when', label: 'When', render: (r) => <span className="mono">{`${date(r.date)} ${r.time}`}</span> },
        { key: 'guest', label: 'Guest', className: 'cell-strong', render: (r) => r.guest_name },
        { key: 'phone', label: 'Phone', className: 'mono', render: (r) => r.guest_phone ?? '—' },
        { key: 'party', label: 'Guests', align: 'right', className: 'mono', render: (r) => r.party_size },
        { key: 'table', label: 'Table', render: (r) => r.table?.name ?? <span className="cell-muted">—</span> },
        { key: 'code', label: 'No.', className: 'mono', render: (r) => r.code },
        { key: 'status', label: 'Status', render: (r) => <Tag tone={r.status.tone}>{r.status.label}</Tag> },
    ];

    return (
        <PageBody>
            <PageToolbar
                title="Reservations"
                primary={
                    can('reservations.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            New Reservation
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.today} today · {counts.guests_today} guests · {counts.upcoming} upcoming
                </span>
            </PageStatus>

            <Tabs
                tabs={[
                    { key: 'calendar', label: 'Calendar' },
                    { key: 'list', label: 'List' },
                ]}
                value={filters.view}
                onChange={(v) => router.get(route('reservations.index'), v === 'list' ? { view: 'list' } : { date: filters.date }, { preserveScroll: true })}
            />

            {isList ? (
                <>
                    <FilterBar count={`${list.meta.total} reservations`}>
                        <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Guest, phone, RSV no.…" />
                        <FilterSelect label="Status" value={query.status} onChange={(v) => setQuery('status', v)} options={[{ value: '', label: 'Upcoming' }, ...statuses]} />
                    </FilterBar>
                    <DataTable columns={listColumns} rows={list.data} meta={list.meta} noun="reservations" empty="No reservations" onRowClick={setEditing} stack />
                </>
            ) : (
                <div className="rsv-layout">
                    <ChartBox
                        className="rsv-calendar"
                        title={month.label}
                        actions={
                            <div className="rsv-month-nav">
                                <button type="button" className="rsv-nav-btn" aria-label="Previous month" onClick={() => visit({ month: otherMonth(month.value, -1), date: filters.date })}>
                                    <ChevronLeft size={14} strokeWidth={1.5} />
                                </button>
                                <Button variant="ghost" onClick={() => visit({ date: today })}>
                                    Today
                                </Button>
                                <button type="button" className="rsv-nav-btn" aria-label="Next month" onClick={() => visit({ month: otherMonth(month.value, 1), date: filters.date })}>
                                    <ChevronRight size={14} strokeWidth={1.5} />
                                </button>
                            </div>
                        }
                    >
                        <div className="rsv-grid">
                            {WEEKDAYS.map((w) => (
                                <div key={w} className="rsv-weekday">
                                    {w}
                                </div>
                            ))}
                            {monthCells(month.value).map((c) => {
                                const info = month.days[c.date];
                                return (
                                    <button
                                        key={c.date}
                                        type="button"
                                        className={cx('rsv-day', !c.inMonth && 'is-out', c.date === today && 'is-today', c.date === filters.date && 'is-selected', c.date < today && 'is-past')}
                                        onClick={() => visit({ month: month.value, date: c.date })}
                                    >
                                        <span className="rsv-day-num">{c.day}</span>
                                        {info && (
                                            <span className="rsv-day-count">
                                                {info.count} · {info.guests}
                                                <Users size={9} strokeWidth={1.5} />
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    </ChartBox>

                    <ChartBox
                        className="rsv-day-panel"
                        title={filters.date === today ? `Today — ${date(filters.date)}` : date(filters.date)}
                        actions={
                            can('reservations.store') &&
                            filters.date >= today && (
                                <Button variant="ghost" icon={CalendarCheck} onClick={() => setEditing({})}>
                                    Book
                                </Button>
                            )
                        }
                    >
                        <div className="rsv-day-sub">
                            <Clock size={11} strokeWidth={1.5} /> now {clock(now)} · {day.length} booking{day.length === 1 ? '' : 's'} ·{' '}
                            {day.filter((r) => r.upcoming || r.status.value === 'seated').reduce((n, r) => n + r.party_size, 0)} guests
                        </div>
                        <DayList reservations={day} onOpen={setEditing} empty="No bookings on this day" />
                    </ChartBox>
                </div>
            )}

            {editing && (
                <ReservationDrawer
                    key={editing.id ?? 'new'}
                    reservation={editing.id ? editing : null}
                    tables={tables}
                    defaultMinutes={defaultMinutes}
                    day={filters.date}
                    now={now}
                    today={today}
                    onClose={() => setEditing(null)}
                    onCancel={() => setCancelling(editing)}
                />
            )}
            {cancelling && (
                <ReasonDialog
                    reservation={cancelling}
                    onClose={() => setCancelling(null)}
                    onDone={() => {
                        setCancelling(null);
                        setEditing(null);
                    }}
                />
            )}
        </PageBody>
    );
}
