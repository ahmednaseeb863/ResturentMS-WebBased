import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import BranchSwitcher from '@/components/layout/BranchSwitcher';
import { LayoutSlotsContext } from '@/components/layout/LayoutSlots';
import FlashToasts from '@/components/ui/FlashToasts';
import { cx } from '@/lib/format';

/**
 * Phone app shell (waiter app, rider panel): the page toolbar on top, the page, and a
 * bottom navigation — `tabs` plus Lock (sign out to the PIN screen for the next person).
 * Installable on the home screen through `manifest`.
 */
export default function MobileShell({ tabs, manifest, label, alerts, children }) {
    const [toolbarEl, setToolbarEl] = useState(null);
    const slots = useMemo(() => ({ toolbarEl, statusEl: null }), [toolbarEl]);

    return (
        <LayoutSlotsContext.Provider value={slots}>
            <Head>
                <link rel="manifest" href={manifest} />
                <meta name="theme-color" content="#5980a6" />
                <meta name="mobile-web-app-capable" content="yes" />
            </Head>
            <div className="waiter-shell">
                <div className="toolbar waiter-top">
                    <div className="toolbar-slot" ref={setToolbarEl} />
                    <BranchSwitcher />
                </div>

                <main className="waiter-main">{children}</main>

                <nav className="waiter-nav" aria-label={label}>
                    {tabs.map(({ key, label: text, icon: Icon, href, active, count }) => (
                        <Link key={key} href={href} className={cx('waiter-nav-item', active && 'active')}>
                            <span className="waiter-nav-icon">
                                <Icon size={18} strokeWidth={1.5} />
                                {count > 0 && <span className="waiter-nav-count">{count}</span>}
                            </span>
                            {text}
                        </Link>
                    ))}
                    <button
                        type="button"
                        className="waiter-nav-item"
                        title="Lock & switch user (PIN)"
                        onClick={() => router.post(route('logout'), { switch: 1 }, { replace: true })}
                    >
                        <Lock size={18} strokeWidth={1.5} />
                        Lock
                    </button>
                </nav>

                {alerts}
                <FlashToasts />
            </div>
        </LayoutSlotsContext.Provider>
    );
}
