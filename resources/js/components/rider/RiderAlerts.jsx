import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Bike, X } from 'lucide-react';
import useLive from '@/hooks/useLive';

const SHOW_FOR = 30000;

function vibrate() {
    try {
        navigator.vibrate?.([120, 60, 120]);
    } catch {
        // not supported
    }
}

/**
 * On the rider's phone (polled, no sockets): a delivery was given to me (`deliveries`
 * event `assigned`) or my delivery's order is ready to pick up (`orders` event `ready`).
 * The phone vibrates; tapping opens my deliveries.
 */
export default function RiderAlerts({ me }) {
    const [alerts, setAlerts] = useState([]);

    function push(fresh) {
        if (!fresh.length) return;
        setAlerts((list) => {
            const latest = [...new Map(fresh.map((e) => [e.code, e])).values()].reverse();
            return [...latest, ...list.filter((a) => !latest.some((e) => e.code === a.code))].slice(0, 3);
        });
        vibrate();
    }

    useLive('deliveries', (events) =>
        push(events.filter((e) => e.kind === 'assigned' && me && e.rider === me).map((e) => ({ ...e, text: `New delivery — ${e.address}`, at: Date.now() }))),
    );
    useLive('orders', (events) =>
        push(events.filter((e) => e.kind === 'ready' && me && e.rider === me).map((e) => ({ ...e, text: 'is ready to pick up', at: Date.now() }))),
    );

    useEffect(() => {
        if (!alerts.length) return undefined;
        const timer = setInterval(() => setAlerts((list) => list.filter((a) => Date.now() - a.at < SHOW_FOR)), 1000);
        return () => clearInterval(timer);
    }, [alerts.length]);

    if (!alerts.length) return null;

    const dismiss = (code) => setAlerts((list) => list.filter((a) => a.code !== code));

    return (
        <div className="ready-alerts waiter-alerts" role="status">
            {alerts.map((a) => (
                <div key={a.code} className="ready-alert">
                    <Bike size={15} strokeWidth={1.5} />
                    <Link href={route('rider.index')} className="ready-alert-text" onClick={() => dismiss(a.code)}>
                        <strong>{a.code}</strong> · {a.text}
                    </Link>
                    <button type="button" className="cust-close" aria-label="Dismiss" onClick={() => dismiss(a.code)}>
                        <X size={12} strokeWidth={1.5} />
                    </button>
                </div>
            ))}
        </div>
    );
}
