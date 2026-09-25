import { useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { LayoutGrid, List, Move, Save, Users, X } from 'lucide-react';
import { Button, Dialog, EmptyState, PageBody, PageStatus, PageToolbar, Tabs, Tag } from '@/components/ui';
import useCan from '@/hooks/useCan';
import { cx } from '@/lib/format';

const TONES = { available: 'accent', occupied: 'info', reserved: 'warn', cleaning: 'neutral' };

/** Live status as a small tag (also used on the tables list). */
export function TableStatusTag({ table }) {
    return <Tag tone={TONES[table.status] ?? 'neutral'}>{table.status_label}</Tag>;
}

const clamp = (v, min, max) => Math.max(min, Math.min(v, max));

function overlaps(a, b) {
    return a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
}

/** First free top-left cell for a w×h table, row by row (same rule as the server). */
function freeSpot(rects, w, h, grid) {
    for (let y = 0; y <= grid.rows - h; y++) {
        for (let x = 0; x <= grid.cols - w; x++) {
            if (!rects.some((r) => overlaps({ x, y, w, h }, r))) return { x, y };
        }
    }
    return null;
}

function TableDialog({ table, statuses, onClose }) {
    const can = useCan();
    const [busy, setBusy] = useState(false);
    const manual = statuses.filter((s) => s.value !== 'occupied');

    function setStatus(status) {
        setBusy(true);
        router.put(route('tables.status', table.id), { status }, { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: onClose });
    }

    return (
        <Dialog open onClose={onClose} title={`Table ${table.name} — ${table.area?.name ?? ''}`}>
            <div className="floor-dialog">
                <div className="floor-dialog-facts">
                    <span>
                        <Users size={13} strokeWidth={1.5} /> {table.capacity} seats
                    </span>
                    <span>{table.shape_label}</span>
                    <TableStatusTag table={table} />
                </div>
                {table.status === 'occupied' ? (
                    <p className="ui-dialog-text">This table has an open order — its status changes with the order.</p>
                ) : (
                    can('tables.status') && (
                        <>
                            <div className="floor-dialog-label">Set status</div>
                            <div className="floor-status-buttons">
                                {manual.map((s) => (
                                    <button
                                        key={s.value}
                                        type="button"
                                        className={cx('floor-status-btn', `fs-${s.value}`, table.status === s.value && 'on')}
                                        aria-pressed={table.status === s.value}
                                        disabled={busy || table.status === s.value}
                                        onClick={() => setStatus(s.value)}
                                    >
                                        {s.label}
                                    </button>
                                ))}
                            </div>
                        </>
                    )
                )}
            </div>
        </Dialog>
    );
}

export default function TablesFloor({ areas, tables, statuses, grid }) {
    const can = useCan();
    const { errors } = usePage().props;
    const [areaId, setAreaId] = useState(areas[0]?.id ?? null);
    const [arranging, setArranging] = useState(false);
    const [moved, setMoved] = useState({}); // id => { x, y } not saved yet
    const [dragging, setDragging] = useState(null);
    const [selected, setSelected] = useState(null);
    const [saving, setSaving] = useState(false);
    const canvas = useRef(null);
    const drag = useRef(null);

    const all = tables;
    const areaTables = all.filter((t) => t.area?.id === areaId);
    const at = (t) => moved[t.id] ?? (t.pos_x === null ? null : { x: t.pos_x, y: t.pos_y });
    const placed = areaTables.filter((t) => at(t));
    const unplaced = areaTables.filter((t) => !at(t));
    const dirty = Object.keys(moved).length > 0;

    const counts = useMemo(() => {
        const c = { available: 0, occupied: 0, reserved: 0, cleaning: 0 };
        all.filter((t) => t.is_active).forEach((t) => (c[t.status] += 1));
        return c;
    }, [all]);

    function rectsExcept(id) {
        return placed.filter((t) => t.id !== id).map((t) => ({ ...at(t), w: t.w, h: t.h }));
    }

    function moveTo(t, x, y) {
        const nx = clamp(x, 0, grid.cols - t.w);
        const ny = clamp(y, 0, grid.rows - t.h);
        const cur = at(t);
        if (cur && cur.x === nx && cur.y === ny) return;
        if (rectsExcept(t.id).some((r) => overlaps({ x: nx, y: ny, w: t.w, h: t.h }, r))) return;
        setMoved((m) => ({ ...m, [t.id]: { x: nx, y: ny } }));
    }

    function cellAt(e) {
        const r = canvas.current.getBoundingClientRect();
        return {
            cx: Math.floor(((e.clientX - r.left) / r.width) * grid.cols),
            cy: Math.floor(((e.clientY - r.top) / r.height) * grid.rows),
        };
    }

    function onPointerDown(e, t) {
        if (!arranging) return;
        e.preventDefault();
        const { cx: x, cy: y } = cellAt(e);
        const p = at(t);
        drag.current = { id: t.id, dx: x - p.x, dy: y - p.y };
        e.currentTarget.setPointerCapture(e.pointerId);
        setDragging(t.id);
    }

    function onPointerMove(e, t) {
        if (drag.current?.id !== t.id) return;
        const { cx: x, cy: y } = cellAt(e);
        moveTo(t, x - drag.current.dx, y - drag.current.dy);
    }

    function onPointerUp() {
        drag.current = null;
        setDragging(null);
    }

    function onKeyDown(e, t) {
        const step = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
        if (!arranging || !step) return;
        e.preventDefault();
        const p = at(t);
        moveTo(t, p.x + step[0], p.y + step[1]);
    }

    function place(t) {
        const spot = freeSpot(rectsExcept(t.id), t.w, t.h, grid);
        if (spot) setMoved((m) => ({ ...m, [t.id]: spot }));
    }

    function stopArranging() {
        setMoved({});
        setArranging(false);
    }

    function save() {
        setSaving(true);
        router.put(
            route('tables.layout'),
            { area: areaId, tables: Object.entries(moved).map(([id, p]) => ({ id, ...p })) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setMoved({});
                    setArranging(false);
                },
                onFinish: () => setSaving(false),
            },
        );
    }

    function switchArea(id) {
        if (dirty && !window.confirm('Discard the changes to this floor plan?')) return;
        setMoved({});
        setAreaId(id);
    }

    const tabs = areas.map((a) => ({ key: a.id, label: a.name, count: all.filter((t) => t.area?.id === a.id).length }));

    return (
        <PageBody>
            <PageToolbar
                title="Floor Plan"
                primary={
                    arranging ? (
                        <Button variant="primary" icon={Save} onClick={save} disabled={!dirty || saving}>
                            {saving ? 'Saving…' : 'Save Layout'}
                        </Button>
                    ) : (
                        can('tables.layout') &&
                        areaId && (
                            <Button variant="primary" icon={Move} className="floor-arrange-btn" onClick={() => setArranging(true)}>
                                Arrange
                            </Button>
                        )
                    )
                }
            >
                {arranging && (
                    <Button variant="ghost" icon={X} onClick={stopArranging}>
                        Cancel
                    </Button>
                )}
                {!arranging && can('tables.index') && (
                    <Button variant="secondary" icon={List} href={route('tables.index')}>
                        Manage Tables
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                <span>{all.filter((t) => t.is_active).length} tables</span>
                {statuses.map((s) => (
                    <span key={s.value} className={`floor-legend fl-${s.value}`}>
                        {counts[s.value]} {s.label.toLowerCase()}
                    </span>
                ))}
            </PageStatus>

            {areas.length === 0 ? (
                <EmptyState
                    icon={LayoutGrid}
                    title="No dining areas yet"
                    action={
                        can('areas.index') && (
                            <Button variant="secondary" href={route('areas.index')}>
                                Dining Areas
                            </Button>
                        )
                    }
                >
                    Add areas (Hall, Rooftop…) and tables first.
                </EmptyState>
            ) : (
                <>
                    <Tabs tabs={tabs} value={areaId} onChange={switchArea} />

                    {arranging && (
                        <div className="floor-arrange-bar">
                            Drag tables to their spot (or select one and use the arrow keys), then save.
                            {errors.tables && <span className="field-error">{errors.tables}</span>}
                        </div>
                    )}

                    <div className="floor-wrap">
                        <div
                            ref={canvas}
                            className={cx('floor-plan', arranging && 'is-arranging')}
                            // eslint-disable-next-line react/forbid-dom-props -- grid size
                            style={{ '--cols': grid.cols, '--rows': grid.rows }}
                        >
                            {placed.map((t) => {
                                const p = at(t);
                                return (
                                    <button
                                        key={t.id}
                                        type="button"
                                        className={cx(
                                            'floor-table',
                                            `ft-${t.shape}`,
                                            `ft-${t.status}`,
                                            !t.is_active && 'is-off',
                                            dragging === t.id && 'is-dragging',
                                            moved[t.id] && 'is-moved',
                                        )}
                                        // eslint-disable-next-line react/forbid-dom-props -- position on the grid
                                        style={{ '--x': p.x, '--y': p.y, '--w': t.w, '--h': t.h }}
                                        onPointerDown={(e) => onPointerDown(e, t)}
                                        onPointerMove={(e) => onPointerMove(e, t)}
                                        onPointerUp={onPointerUp}
                                        onPointerCancel={onPointerUp}
                                        onKeyDown={(e) => onKeyDown(e, t)}
                                        onClick={() => !arranging && setSelected(t)}
                                        aria-label={`Table ${t.name}, ${t.capacity} seats, ${t.status_label}`}
                                    >
                                        <span className="floor-table-name">{t.name}</span>
                                        <span className="floor-table-seats">
                                            <Users size={11} strokeWidth={1.5} />
                                            {t.capacity}
                                        </span>
                                    </button>
                                );
                            })}
                            {placed.length === 0 && <div className="floor-empty">No tables on this plan yet</div>}
                        </div>
                    </div>

                    {/* phones: a simple card grid instead of the plan */}
                    <div className="floor-cards">
                        {areaTables.map((t) => (
                            <button
                                key={t.id}
                                type="button"
                                className={cx('floor-card', `ft-${t.status}`, !t.is_active && 'is-off')}
                                onClick={() => setSelected(t)}
                            >
                                <span className="floor-table-name">{t.name}</span>
                                <span className="floor-table-seats">
                                    <Users size={11} strokeWidth={1.5} />
                                    {t.capacity}
                                </span>
                                <span className="floor-card-status">{t.status_label}</span>
                            </button>
                        ))}
                        {areaTables.length === 0 && <div className="floor-empty">No tables in this area</div>}
                    </div>

                    {unplaced.length > 0 && (
                        <div className="floor-tray">
                            <span className="floor-tray-label">Not on the plan:</span>
                            {unplaced.map((t) =>
                                arranging ? (
                                    <Button key={t.id} variant="secondary" className="btn-xs" onClick={() => place(t)}>
                                        Place {t.name}
                                    </Button>
                                ) : (
                                    <button key={t.id} type="button" className="floor-tray-item" onClick={() => setSelected(t)}>
                                        {t.name}
                                    </button>
                                ),
                            )}
                        </div>
                    )}
                </>
            )}

            {selected && (
                <TableDialog
                    key={selected.id}
                    table={all.find((t) => t.id === selected.id) ?? selected}
                    statuses={statuses}
                    onClose={() => setSelected(null)}
                />
            )}
        </PageBody>
    );
}
