import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { BellRing, ReceiptText, X } from 'lucide-react';
import useLive from '@/hooks/useLive';
import { money } from '@/lib/format';

const SHOW_FOR = 20000;

/**
 * "Order #012 · T5 is ready" — the kitchen finished every item of an order — and
 * "#012 · T5 asks for the bill" from the waiter app (polled with useLive). Stacks up to
 * three alerts; the open-orders list refreshes too.
 */
export default function ReadyAlerts() {
    const [alerts, setAlerts] = useState([]);

    useLive('orders', (events) => {
        // the whole order ready, or a waiter asking for the bill (station-level alerts are the waiter app's)
        const fresh = events.filter((e) => e.kind !== 'items_ready').map((e) => ({ ...e, at: Date.now() }));
        if (!fresh.length) return;
        setAlerts((list) => [...fresh.reverse(), ...list.filter((a) => !fresh.some((e) => e.id === a.id && e.kind === a.kind))].slice(0, 3));
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
                <div key={`${a.id}:${a.kind}`} className="ready-alert">
                    {a.kind === 'bill' ? <ReceiptText size={15} strokeWidth={1.5} /> : <BellRing size={15} strokeWidth={1.5} />}
                    <Link href={route('pos.index', { order: a.id })} className="ready-alert-text">
                        <strong>{a.code}</strong> · {a.label} {a.kind === 'bill' ? `asks for the bill (${money(a.total)})` : 'is ready'}
                    </Link>
                    <button
                        type="button"
                        className="cust-close"
                        aria-label="Dismiss"
                        onClick={() => setAlerts((list) => list.filter((x) => x !== a))}
                    >
                        <X size={12} strokeWidth={1.5} />
                    </button>
                </div>
            ))}
        </div>
    );
}
