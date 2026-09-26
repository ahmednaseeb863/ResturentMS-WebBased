import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { BellRing, X } from 'lucide-react';
import useLive from '@/hooks/useLive';

const SHOW_FOR = 20000;

/**
 * "Order #012 · T5 is ready" — the kitchen finished every item of an order (polled with
 * useLive). Stacks up to three alerts; the open-orders list refreshes too.
 */
export default function ReadyAlerts() {
    const [alerts, setAlerts] = useState([]);

    useLive('orders', (events) => {
        if (!events.length) return;
        const fresh = events.map((e) => ({ ...e, at: Date.now() }));
        setAlerts((list) => [...fresh.reverse(), ...list.filter((a) => !fresh.some((e) => e.id === a.id))].slice(0, 3));
        router.reload({ only: ['openOrders'] });
    });

    useEffect(() => {
        if (!alerts.length) return undefined;
        const timer = setInterval(() => setAlerts((list) => list.filter((a) => Date.now() - a.at < SHOW_FOR)), 1000);
        return () => clearInterval(timer);
    }, [alerts.length]);

    if (!alerts.length) return null;

    return (
        <div className="ready-alerts" role="status">
            {alerts.map((a) => (
                <div key={a.id} className="ready-alert">
                    <BellRing size={15} strokeWidth={1.5} />
                    <Link href={route('pos.index', { order: a.id })} className="ready-alert-text">
                        <strong>{a.code}</strong> · {a.label} is ready
                    </Link>
                    <button
                        type="button"
                        className="cust-close"
                        aria-label="Dismiss"
                        onClick={() => setAlerts((list) => list.filter((x) => x.id !== a.id))}
                    >
                        <X size={12} strokeWidth={1.5} />
                    </button>
                </div>
            ))}
        </div>
    );
}
