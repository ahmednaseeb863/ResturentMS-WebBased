import { cx } from '@/lib/format';

/** Underlined tabs (pos-react `.cust-tabs`). tabs: [{ key, label, count }] */
export default function Tabs({ tabs, value, onChange, className }) {
    return (
        <div className={cx('cust-tabs', className)} role="tablist">
            {tabs.map((t) => (
                <button
                    key={t.key}
                    type="button"
                    role="tab"
                    aria-selected={value === t.key}
                    className={cx('cust-tab', value === t.key && 'active')}
                    onClick={() => onChange(t.key)}
                >
                    {t.label}
                    {t.count !== undefined && <span className="cust-tab-count">{t.count}</span>}
                </button>
            ))}
        </div>
    );
}
