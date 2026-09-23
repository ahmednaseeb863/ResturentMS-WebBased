import Tabs from './Tabs';

/**
 * Active / Trash tabs for every list (CLAUDE.md §1). The Trash tab only shows
 * when the admin may restore (`canRestore`). `counts` = { active, trash }.
 */
export default function TrashTabs({ value, onChange, counts, canRestore, activeLabel = 'Active' }) {
    const tabs = [{ key: 'active', label: activeLabel, count: counts?.active }];
    if (canRestore) tabs.push({ key: 'trash', label: 'Trash', count: counts?.trash });

    return <Tabs tabs={tabs} value={value} onChange={onChange} className="list-tabs" />;
}
