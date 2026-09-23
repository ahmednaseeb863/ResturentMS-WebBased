import { Link, usePage } from '@inertiajs/react';
import { navSections } from '@/lib/nav';
import { cx, initials } from '@/lib/format';
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

    return (
        <aside className="sidebar" id="app-sidebar">
            {navSections.map((group) => (
                <div key={group.section} className="sidebar-group">
                    <div className="sidebar-section">{group.section}</div>
                    {group.items.map((item) => (
                        <NavItem key={item.route} item={item} />
                    ))}
                </div>
            ))}

            <div className="sidebar-user">
                <div className="sidebar-avatar">{user ? initials(user.name) : '—'}</div>
                <div className="sidebar-user-text">
                    <div className="sidebar-user-name">{user?.name ?? 'Not signed in'}</div>
                    <div className="sidebar-user-role">{user?.role ?? 'Guest'}</div>
                </div>
                <ThemeToggle />
            </div>
        </aside>
    );
}
