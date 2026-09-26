import { usePage } from '@inertiajs/react';
import { Bike, Wallet } from 'lucide-react';
import RiderAlerts from '@/components/rider/RiderAlerts';
import { configureFormat } from '@/lib/format';
import MobileShell from './MobileShell';

/**
 * Rider panel shell (PLAN §4.14) — my deliveries · my cash · lock. Installable on the
 * phone's home screen (rider.webmanifest).
 */
export default function RiderLayout({ children }) {
    const { props } = usePage();

    configureFormat(props.context?.settings);

    const cash = props.view === 'cash';
    const tabs = [
        { key: 'deliveries', label: 'Deliveries', icon: Bike, href: route('rider.index'), active: !cash, count: props.deliveries?.length ?? 0 },
        { key: 'cash', label: 'My Cash', icon: Wallet, href: route('rider.index', { view: 'cash' }), active: cash },
    ];

    return (
        <MobileShell tabs={tabs} manifest="/rider.webmanifest" label="Rider panel" alerts={<RiderAlerts me={props.me?.id ?? null} />}>
            {children}
        </MobileShell>
    );
}
