import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { BellRing, X } from 'lucide-react';
import useLive from '@/hooks/useLive';

const SHOW_FOR = 30000;

/**
 * "Table 5 · Grill items are ready" on the waiter's phone (polled, no sockets): a station
 * finished its ticket (`items_ready`) or the whole order is ready (`ready`). Only the
 * signed-in waiter's orders when they have a staff record (`me`); the phone vibrates.
 * Tapping opens the table.
 */
export default function WaiterAlerts({ me }) {
    const [alerts, setAlerts] = useState([]);

    useLive('orders', (events) => {
        const fresh = events
            .filter((e) => (e.kind === 'ready' || e.kind === 'items_ready') && e.table && (!me || !e.waiter || e.waiter === me))
            .map((e) => ({ ...e, at: Date.now() }));
        if (!fresh.length) return;

        // one alert per order: the newest wins ("all ready" replaces "Grill items ready")
        setAlerts((list) => {
            const latest = [...new Map(fresh.map((e) => [e.id, e])).values()].reverse();
            return [...latest, ...list.filter((a) => !latest.some((e) => e.id === a.id))].slice(0, 3);
        });
        try {
            navigator.vibrate?.([120, 60, 120]);
        } catch {
            // not supported
        }
    });

    useEffect(() => {
        if (!alerts.length) return undefined;
        const timer = setInterval(() => setAlerts((list) => list.filter((a) => Date.now() - a.at < SHOW_FOR)), 1000);
        return () => clearInterval(timer);
    }, [alerts.length]);

    if (!alerts.length) return null;

    const dismiss = (id) => setAlerts((list) => list.filter((a) => a.id !== id));

    return (
        <div className="ready-alerts waiter-alerts" role="status">
            {alerts.map((a) => (
                <div key={a.id} className="ready-alert">
                    <BellRing size={15} strokeWidth={1.5} />
                    <Link href={route('waiter.table', a.table)} className="ready-alert-text" onClick={() => dismiss(a.id)}>
                        <strong>{a.label}</strong> · {a.kind === 'ready' ? `${a.code} is ready to serve` : `${a.station ?? 'Kitchen'} items are ready`}
                    </Link>
                    <button type="button" className="cust-close" aria-label="Dismiss" onClick={() => dismiss(a.id)}>
                        <X size={12} strokeWidth={1.5} />
                    </button>
                </div>
            ))}
        </div>
    );
}
