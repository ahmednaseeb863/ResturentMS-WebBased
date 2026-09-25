import { DataTable, FilterBar, FilterSelect, PageBody, PageToolbar, SearchInput, Tag } from '@/components/ui';
import useListQuery from '@/hooks/useListQuery';
import { dateTime } from '@/lib/format';

const EVENT_TONES = { created: 'accent', trashed: 'warn', restored: 'info', permissions: 'accent' };

const label = (key) => key.replaceAll('_', ' ');

function show(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (Array.isArray(value)) return value.join(', ');
    return String(value);
}

/** Readable summary of what changed. */
function Details({ log }) {
    const p = log.properties ?? {};
    const lines = [];

    if (p.old && p.attributes) {
        Object.keys(p.attributes).forEach((k) =>
            lines.push(
                <span key={k}>
                    <b>{label(k)}</b>: {show(p.old[k])} → {show(p.attributes[k])}
                </span>,
            ),
        );
    } else if (p.attributes) {
        Object.keys(p.attributes).forEach((k) =>
            lines.push(
                <span key={k}>
                    <b>{label(k)}</b>: {show(p.attributes[k])}
                </span>,
            ),
        );
    }

    // any other summary: granted / revoked, added / removed, recipe lines, copy counts…
    Object.keys(p).forEach((k) => {
        if (k === 'old' || k === 'attributes' || (p[k] !== null && typeof p[k] === 'object' && !Array.isArray(p[k]))) {
            return;
        }
        if (p[k] !== '' && p[k] !== null && (!Array.isArray(p[k]) || p[k].length)) {
            lines.push(
                <span key={k}>
                    <b>{label(k)}</b>: {show(p[k])}
                </span>,
            );
        }
    });

    return lines.length ? <div className="activity-changes">{lines}</div> : <span className="cell-muted">—</span>;
}

export default function ActivityIndex({ logs, events, filters }) {
    const [query, setQuery] = useListQuery(filters);

    const columns = [
        { key: 'created_at', label: 'When', className: 'mono', render: (r) => dateTime(r.created_at) },
        { key: 'admin', label: 'By', render: (r) => r.admin?.name ?? 'System' },
        {
            key: 'event',
            label: 'Action',
            render: (r) => <Tag tone={EVENT_TONES[r.event] ?? 'neutral'}>{label(r.event)}</Tag>,
        },
        { key: 'module', label: 'Module', render: (r) => r.module ?? '—' },
        { key: 'subject', label: 'Record', className: 'cell-strong', render: (r) => r.subject ?? '—' },
        { key: 'details', label: 'Details', render: (r) => <Details log={r} /> },
        { key: 'branch', label: 'Branch', render: (r) => r.branch?.name ?? '—' },
    ];

    return (
        <PageBody>
            <PageToolbar title="Activity Log" />

            <FilterBar count={`${logs.meta.total} entries`}>
                <SearchInput
                    value={query.search}
                    onChange={(v) => setQuery('search', v)}
                    placeholder="Search by record or person…"
                />
                <FilterSelect
                    label="Action"
                    value={query.event}
                    onChange={(v) => setQuery('event', v)}
                    options={[{ value: '', label: 'All' }, ...events.map((e) => ({ value: e, label: label(e) }))]}
                />
            </FilterBar>

            <DataTable columns={columns} rows={logs.data} meta={logs.meta} noun="entries" stack />
        </PageBody>
    );
}
