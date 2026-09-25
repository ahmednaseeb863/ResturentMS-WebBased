import { cx } from '@/lib/format';

const CARDS = [
    { key: 'active', label: 'Active today' },
    { key: 'scheduled', label: 'Scheduled' },
    { key: 'expired', label: 'Expired' },
    { key: 'inactive', label: 'Switched off' },
];

/** pos-react promotion stat cards; clicking one filters the list by that status. */
export default function OfferStats({ counts, value, onChange }) {
    return (
        <div className="promo-stat-row">
            {CARDS.map((c) => (
                <button
                    key={c.key}
                    type="button"
                    className={cx('promo-stat-card', value === c.key && 'selected')}
                    aria-pressed={value === c.key}
                    onClick={() => onChange(value === c.key ? '' : c.key)}
                >
                    <div className={`promo-stat-num promo-stat-${c.key}`}>{counts[c.key] ?? 0}</div>
                    <div className="promo-stat-label">{c.label}</div>
                </button>
            ))}
        </div>
    );
}

/** "1 Oct 2026 – 31 Oct 2026", "From 1 Oct 2026", "Until …", or null (no dates). */
export function dateRange(from, to, format) {
    if (from && to) return `${format(from)} – ${format(to)}`;
    if (from) return `From ${format(from)}`;
    if (to) return `Until ${format(to)}`;
    return null;
}
