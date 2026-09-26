import { useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { BellRing, LayoutGrid, Lock, UserRound } from 'lucide-react';
import BranchSwitcher from '@/components/layout/BranchSwitcher';
import { LayoutSlotsContext } from '@/components/layout/LayoutSlots';
import FlashToasts from '@/components/ui/FlashToasts';
import WaiterAlerts from '@/components/waiter/WaiterAlerts';
import { configureFormat, cx } from '@/lib/format';

/**
 * Waiter app shell (PLAN §4.11) — mobile first: the page toolbar on top, the page, and a
 * bottom navigation (all tables · my tables · ready to serve · lock for the next waiter).
 * Installable on the phone's home screen (waiter.webmanifest).
 */
export default function WaiterLayout({ children }) {
    const { url, props } = usePage();
    const [toolbarEl, setToolbarEl] = useState(null);
    const slots = useMemo(() => ({ toolbarEl, statusEl: null }), [toolbarEl]);

    configureFormat(props.context?.settings);

    const view = new URLSearchParams(url.split('?')[1] ?? '').get('view') ?? 'all';
    const onTables = url.startsWith('/waiter') && !url.startsWith('/waiter/tables');
    const tabs = [
        { key: 'all', label: 'Tables', icon: LayoutGrid, href: route('waiter.index') },
        { key: 'mine', label: 'My Tables', icon: UserRound, href: route('waiter.index', { view: 'mine' }) },
        { key: 'ready', label: 'Ready', icon: BellRing, href: route('waiter.index', { view: 'ready' }) },
    ];

    return (
        <LayoutSlotsContext.Provider value={slots}>
            <Head>
                <link rel="manifest" href="/waiter.webmanifest" />
                <meta name="theme-color" content="#5980a6" />
                <meta name="mobile-web-app-capable" content="yes" />
            </Head>
            <div className="waiter-shell">
                <div className="toolbar waiter-top">
                    <div className="toolbar-slot" ref={setToolbarEl} />
                    <BranchSwitcher />
                </div>

                <main className="waiter-main">{children}</main>

                <nav className="waiter-nav" aria-label="Waiter app">
                    {tabs.map(({ key, label, icon: Icon, href }) => (
                        <Link key={key} href={href} className={cx('waiter-nav-item', (onTables ? view === key || (key === 'all' && view === 'free') : key === 'all') && 'active')}>
                            <Icon size={18} strokeWidth={1.5} />
                            {label}
                        </Link>
                    ))}
                    <button
                        type="button"
                        className="waiter-nav-item"
                        title="Lock & switch waiter (PIN)"
                        onClick={() => router.post(route('logout'), { switch: 1 }, { replace: true })}
                    >
                        <Lock size={18} strokeWidth={1.5} />
                        Lock
                    </button>
                </nav>

                <WaiterAlerts me={props.me ?? null} />
                <FlashToasts />
            </div>
        </LayoutSlotsContext.Provider>
    );
}
