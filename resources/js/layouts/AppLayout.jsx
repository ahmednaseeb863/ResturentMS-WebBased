import { useEffect, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Sidebar from '@/components/layout/Sidebar';
import Toolbar from '@/components/layout/Toolbar';
import StatusBar from '@/components/layout/StatusBar';
import { LayoutSlotsContext } from '@/components/layout/LayoutSlots';
import FlashToasts from '@/components/ui/FlashToasts';
import { configureFormat, cx } from '@/lib/format';

/**
 * App shell — pos-react MainLayout (sidebar · toolbar · content · status bar),
 * persistent across Inertia visits. Responsive behaviour lives in responsive.css:
 * ≥1024 full sidebar · 768–1023 icon rail · <768 off-canvas drawer.
 */
export default function AppLayout({ hideSidebar = false, children }) {
    const [navOpen, setNavOpen] = useState(false);
    const [toolbarEl, setToolbarEl] = useState(null);
    const [statusEl, setStatusEl] = useState(null);

    // currency symbol / time format from settings, before children format anything
    configureFormat(usePage().props.context?.settings);

    // Close the mobile drawer / expanded rail after every navigation.
    useEffect(() => router.on('navigate', () => setNavOpen(false)), []);

    useEffect(() => {
        if (!navOpen) return undefined;
        const onKey = (e) => e.key === 'Escape' && setNavOpen(false);
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [navOpen]);

    const slots = useMemo(() => ({ toolbarEl, statusEl }), [toolbarEl, statusEl]);

    return (
        <LayoutSlotsContext.Provider value={slots}>
            <div className={cx('app-shell flex flex-col', navOpen && 'nav-open', hideSidebar && 'no-sidebar')}>
                <div className="flex flex-1 overflow-hidden">
                    {!hideSidebar && (
                        <>
                            <Sidebar />
                            <div className="sidebar-backdrop" onClick={() => setNavOpen(false)} aria-hidden="true" />
                        </>
                    )}

                    <div className="flex flex-col flex-1 overflow-hidden min-w-0">
                        <Toolbar
                            slotRef={setToolbarEl}
                            showMenuButton={!hideSidebar}
                            navOpen={navOpen}
                            onMenu={() => setNavOpen((o) => !o)}
                        />
                        <main className="app-main flex-1 overflow-y-auto">{children}</main>
                        <StatusBar slotRef={setStatusEl} />
                    </div>
                </div>
                <FlashToasts />
            </div>
        </LayoutSlotsContext.Provider>
    );
}
