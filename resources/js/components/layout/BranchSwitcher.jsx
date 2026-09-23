import { router, usePage } from '@inertiajs/react';
import { Store } from 'lucide-react';

/**
 * Current-branch picker for users with access to more than one branch.
 * Data (uuids only) comes from the shared `context` prop; switching reloads
 * the dashboard in the new branch.
 */
export default function BranchSwitcher() {
    const { context } = usePage().props;
    const branches = context?.branches ?? [];

    if (branches.length < 2) return null;

    function change(e) {
        router.post(route('branch.switch'), { branch: e.target.value }, { preserveScroll: true });
    }

    return (
        <label className="branch-switcher" title="Switch branch">
            <Store size={13} strokeWidth={1.5} />
            <select className="fselect" value={context.branch?.id ?? ''} onChange={change}>
                {branches.map((b) => (
                    <option key={b.id} value={b.id}>
                        {b.name}
                    </option>
                ))}
            </select>
        </label>
    );
}
