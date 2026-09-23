import { usePage } from '@inertiajs/react';

/**
 * Route-name permissions on the client (mirrors the `permission` middleware):
 *   const can = useCan();  can('branches.store') && <Button …/>
 * Only hides UI — the server always re-checks.
 */
export default function useCan() {
    const permissions = usePage().props.auth?.permissions;

    return (routeName) => {
        if (!permissions) return false;
        if (permissions.all) return true;
        return permissions.routes.includes(routeName);
    };
}
