import { Link, router, usePage } from '@inertiajs/react';
import { Lock, LogOut } from 'lucide-react';
import { navSections } from '@/lib/nav';
import { cx } from '@/lib/format';
import useCan from '@/hooks/useCan';
import ThemeToggle from './ThemeToggle';

function isActive(name) {
    // "customers.index" is active on every "customers.*" page.
    const group = name.split('.')[0];
    return route().current(name) || route().current(`${group}.*`);
}

function NavItem({ item }) {
    const Icon = item.icon;
    const content = (
        <>
            <Icon size={16} strokeWidth={1.5} />
            <span className="sidebar-label">{item.label}</span>
        </>
    );

    // Screen not built yet — keep it visible so the menu is complete, but inert.
    if (!route().has(item.route)) {
        return (
            <span className="sidebar-item disabled" title={`${item.label} — coming soon`} aria-disabled="true">
                {content}
            </span>
        );
    }

    return (
        <Link
            href={route(item.route)}
            className={cx('sidebar-item', isActive(item.route) && 'active')}
            title={item.label}
            prefetch
        >
            {content}
        </Link>
    );
}

export default function Sidebar() {
    const { auth } = usePage().props;
    const user = auth?.user;
    const can = useCan();

    // Built screens show only when permitted; future screens stay visible (disabled).
    const sections = navSections
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => !route().has(item.route) || can(item.route)),
        }))
        .filter((group) => group.items.length > 0);

    const signOut = (switchUser = false) =>
        router.post(route('logout'), switchUser ? { switch: 1 } : {}, { replace: true });

    return (
        <aside className="sidebar" id="app-sidebar">
            {sections.map((group) => (
                <div key={group.section} className="sidebar-group">
                    <div className="sidebar-section">{group.section}</div>
                    {group.items.map((item) => (
                        <NavItem key={item.route} item={item} />
                    ))}
                </div>
            ))}

            <div className="sidebar-user">
                <div className="sidebar-avatar">{user?.initials ?? '—'}</div>
                <div className="sidebar-user-text">
                    <div className="sidebar-user-name">{user?.name ?? 'Not signed in'}</div>
                    <div className="sidebar-user-role">{user?.role ?? 'No role'}</div>
                </div>
                <ThemeToggle />
            </div>
            {user && (
                <div className="sidebar-user-actions">
                    <button type="button" onClick={() => signOut(true)} title="Lock & switch user (PIN)">
                        <Lock size={13} strokeWidth={1.5} />
                        <span className="sidebar-label">Switch user</span>
                    </button>
                    <button type="button" onClick={() => signOut()} title="Sign out">
                        <LogOut size={13} strokeWidth={1.5} />
                        <span className="sidebar-label">Sign out</span>
                    </button>
                </div>
            )}
        </aside>
    );
}
