import { usePage } from '@inertiajs/react';
import { BellRing, LayoutGrid, UserRound } from 'lucide-react';
import WaiterAlerts from '@/components/waiter/WaiterAlerts';
import { configureFormat } from '@/lib/format';
import MobileShell from './MobileShell';

/**
 * Waiter app shell (PLAN §4.11) — mobile first: all tables · my tables · ready to serve ·
 * lock for the next waiter. Installable on the phone's home screen (waiter.webmanifest).
 */
export default function WaiterLayout({ children }) {
    const { url, props } = usePage();

    configureFormat(props.context?.settings);

    const view = new URLSearchParams(url.split('?')[1] ?? '').get('view') ?? 'all';
    const onTables = url.startsWith('/waiter') && !url.startsWith('/waiter/tables');
    const active = (key) => (onTables ? view === key || (key === 'all' && view === 'free') : key === 'all');
    const tabs = [
        { key: 'all', label: 'Tables', icon: LayoutGrid, href: route('waiter.index') },
        { key: 'mine', label: 'My Tables', icon: UserRound, href: route('waiter.index', { view: 'mine' }) },
        { key: 'ready', label: 'Ready', icon: BellRing, href: route('waiter.index', { view: 'ready' }) },
    ].map((t) => ({ ...t, active: active(t.key) }));

    return (
        <MobileShell tabs={tabs} manifest="/waiter.webmanifest" label="Waiter app" alerts={<WaiterAlerts me={props.me ?? null} />}>
            {children}
        </MobileShell>
    );
}
