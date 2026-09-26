import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ChefHat, History, LogOut, Maximize, Printer, RefreshCw } from 'lucide-react';
import { Button, ConfirmDialog, EmptyState, PageStatus, PageToolbar, Tabs } from '@/components/ui';
import ConsumptionDialog from '@/components/kitchen/ConsumptionDialog';
import ServedDrawer from '@/components/kitchen/ServedDrawer';
import TicketCard from '@/components/kitchen/TicketCard';
import PrintDeviceDialog from '@/components/printing/PrintDeviceDialog';
import { useDevicePrinters } from '@/components/printing/PrintAgent';
import useCan from '@/hooks/useCan';
import useLive from '@/hooks/useLive';
import useNow from '@/hooks/useNow';

const BOARD = ['tickets', 'stations', 'unrouted'];
const STATION_KEY = 'rms-kitchen-station';

/** Short beep when a new ticket arrives (ignored until the page has been touched once). */
function chime() {
    try {
        const audio = new (window.AudioContext || window.webkitAudioContext)();
        const tone = audio.createOscillator();
        const gain = audio.createGain();
        tone.frequency.value = 880;
        gain.gain.setValueAtTime(0.15, audio.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.4);
        tone.connect(gain).connect(audio.destination);
        tone.start();
        tone.stop(audio.currentTime + 0.4);
        tone.onended = () => audio.close();
    } catch {
        // no audio on this device
    }
}

function rememberStation(id) {
    try {
        if (id) window.localStorage.setItem(STATION_KEY, id);
        else window.localStorage.removeItem(STATION_KEY);
    } catch {
        // storage blocked
    }
}

/**
 * Kitchen display (PLAN §4.12): tickets of one station (or all), oldest first, coloured by
 * waiting time. Start → Ready (confirm raw materials) → Served / Collected; recall; reprint.
 * Refreshes itself by polling (useLive); a kitchen PC can also print its station's tickets.
 */
export default function KitchenIndex() {
    const { tickets, stations, unrouted, station, rules, printers, confirm, materials, done } = usePage().props;
    const can = useCan();
    const now = useNow(1000);
    const devicePrinters = useDevicePrinters();

    const [dialog, setDialog] = useState(null); // { kind: 'confirm' | 'served' | 'printing' | 'reprint', … }
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    // reload the board when the kitchen changed (polled — no sockets), and every minute anyway
    const refresh = useLive('kitchen', () => router.reload({ only: BOARD }));
    useEffect(() => {
        const timer = setInterval(() => router.reload({ only: BOARD }), 60000);
        return () => clearInterval(timer);
    }, []);

    // this screen's station is remembered on the device
    useEffect(() => {
        let saved = null;
        try {
            saved = window.localStorage.getItem(STATION_KEY);
        } catch {
            // storage blocked
        }
        if (!station && saved && stations.some((s) => s.id === saved) && !window.location.search.includes('station=')) {
            router.get(route('kitchen.index', { station: saved }), {}, { replace: true, preserveState: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // chime for tickets that weren't on the board before
    const seen = useRef(null);
    useEffect(() => {
        const ids = new Set(tickets.map((t) => t.id));
        if (seen.current && tickets.some((t) => !seen.current.has(t.id) && t.status.value === 'pending')) chime();
        seen.current = ids;
    }, [tickets]);

    function pickStation(id) {
        rememberStation(id === 'all' ? null : id);
        router.get(route('kitchen.index', id === 'all' ? {} : { station: id }), {}, { preserveState: true, replace: true });
    }

    function act(name, ticket, data = {}, after) {
        setBusy(true);
        setError(null);
        router.put(route(name, ticket.id), data, {
            preserveScroll: true,
            preserveState: true,
            only: [...BOARD, 'errors', 'flash'],
            onError: (errors) => setError(Object.values(errors)[0]),
            onSuccess: () => after?.(),
            onFinish: () => setBusy(false),
        });
    }

    /** Ready a line or the whole ticket; asks the cook to confirm raw materials when needed. */
    function ready(ticket, item) {
        const lines = item ? [item] : ticket.items.filter((i) => !i.voided && (i.status === 'pending' || i.status === 'preparing'));
        const needsConfirm = rules.confirm_consumption && lines.some((i) => i.confirm);
        const items = item ? [item.id] : [];

        if (!needsConfirm) return act('kitchen.tickets.ready', ticket, { items });

        setBusy(true);
        router.reload({
            only: ['confirm', 'materials'],
            data: { ticket: ticket.id, item: item?.id },
            preserveUrl: true,
            onSuccess: () => setDialog({ kind: 'confirm', ticket, items, title: item ? `${item.quantity} × ${item.name}` : `${ticket.code} · ${ticket.order.code}` }),
            onFinish: () => setBusy(false),
        });
    }

    function openServed() {
        setDialog({ kind: 'served' });
        router.reload({ only: ['done'], preserveUrl: true });
    }

    const stationTabs = [
        { key: 'all', label: 'All', count: stations.reduce((n, s) => n + s.count, 0) + unrouted },
        ...stations.map((s) => ({ key: s.id, label: s.name, count: s.count })),
    ];
    const printerOf = Object.fromEntries(stations.map((s) => [s.id, s.printer]));
    const counts = {
        new: tickets.filter((t) => t.status.value === 'pending').length,
        cooking: tickets.filter((t) => t.status.value === 'preparing').length,
        ready: tickets.filter((t) => t.status.value === 'ready').length,
    };
    const printingHere = printers.filter((p) => devicePrinters.includes(p.id));

    return (
        <>
            <PageToolbar
                title="Kitchen Display"
                primary={
                    <Button variant="danger" icon={LogOut} href={route('dashboard')}>
                        Exit
                    </Button>
                }
            >
                <Button icon={History} onClick={openServed}>
                    Recently Served
                </Button>
                {can('print-jobs.pending') && (
                    <Button icon={Printer} onClick={() => setDialog({ kind: 'printing' })}>
                        {printingHere.length ? `Printing: ${printingHere.map((p) => p.name).join(', ')}` : 'Printing: off'}
                    </Button>
                )}
                <Button variant="ghost" icon={Maximize} onClick={() => document.documentElement.requestFullscreen?.().catch(() => {})}>
                    Full Screen
                </Button>
            </PageToolbar>
            <PageStatus>
                <span className="kds-live">
                    <RefreshCw size={12} strokeWidth={1.5} />
                    Checks every {refresh}s
                </span>
                <span>
                    {counts.new} new · {counts.cooking} cooking · {counts.ready} ready
                </span>
            </PageStatus>

            <div className="kds-page">
                <Tabs className="kds-tabs" tabs={stationTabs} value={station ?? 'all'} onChange={pickStation} />

                {!rules.kds && (
                    <div className="pos-no-shift">
                        <ChefHat size={14} strokeWidth={1.5} />
                        <span>The kitchen display is switched off in Settings → Printing; tickets still show here.</span>
                    </div>
                )}
                {error && (
                    <div className="kds-error" role="alert">
                        {error}
                    </div>
                )}

                {tickets.length === 0 ? (
                    <EmptyState title="All caught up">New kitchen tickets appear here as soon as orders are sent.</EmptyState>
                ) : (
                    <div className="kds-board">
                        {tickets.map((t) => (
                            <TicketCard
                                key={t.id}
                                ticket={t}
                                now={now}
                                rules={rules}
                                showStation={!station}
                                can={can}
                                busy={busy}
                                onStart={(ticket) => act('kitchen.tickets.start', ticket)}
                                onReady={ready}
                                onServe={(ticket) => act('kitchen.tickets.serve', ticket)}
                                onRecall={(ticket) => act('kitchen.tickets.recall', ticket)}
                                onReprint={
                                    can('kitchen.tickets.reprint') && t.station && printerOf[t.station.id]
                                        ? (ticket) => setDialog({ kind: 'reprint', ticket })
                                        : null
                                }
                            />
                        ))}
                    </div>
                )}
            </div>

            {dialog?.kind === 'confirm' && confirm && (
                <ConsumptionDialog
                    title={`Ready — ${dialog.title}`}
                    rows={confirm}
                    materials={materials ?? []}
                    processing={busy}
                    error={error}
                    onClose={() => setDialog(null)}
                    onConfirm={(consumption) =>
                        act('kitchen.tickets.ready', dialog.ticket, { items: dialog.items, consumption }, () => setDialog(null))
                    }
                />
            )}
            {dialog?.kind === 'served' && (
                <ServedDrawer
                    tickets={done}
                    loading
                    canRecall={can('kitchen.tickets.recall')}
                    onClose={() => setDialog(null)}
                    onRecall={(ticket) => act('kitchen.tickets.recall', ticket, {}, () => setDialog(null))}
                />
            )}
            {dialog?.kind === 'printing' && <PrintDeviceDialog printers={printers} onClose={() => setDialog(null)} />}
            <ConfirmDialog
                open={dialog?.kind === 'reprint'}
                title="Reprint Kitchen Ticket"
                message={dialog?.ticket ? `Print ${dialog.ticket.code} (${dialog.ticket.order.code}) again on the station printer?` : ''}
                confirmLabel="Reprint"
                onClose={() => setDialog(null)}
                onConfirm={() =>
                    router.post(route('kitchen.tickets.reprint', dialog.ticket.id), {}, { preserveScroll: true, preserveState: true, onFinish: () => setDialog(null) })
                }
            />
        </>
    );
}
