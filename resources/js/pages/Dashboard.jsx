import { ChartBox, PageStatus, PageToolbar, StatCard, StatGrid, Tag } from '@/components/ui';
import { cx } from '@/lib/format';

// Phase 0: static demo data from DashboardController — replaced in Phase 15.
export default function Dashboard({ demo, stats, week, activity, alerts }) {
    return (
        <div className="p-5 dashboard">
            <PageToolbar title="Dashboard">{demo && <Tag tone="warn">Demo data</Tag>}</PageToolbar>
            <PageStatus>
                <span>Connected</span>
            </PageStatus>

            <StatGrid>
                {stats.map((s) => (
                    <StatCard key={s.label} label={s.label} value={s.value} sub={s.sub} tone={s.tone} />
                ))}
            </StatGrid>

            <div className="dash-row">
                <ChartBox title="Sales — Last 7 Days">
                    <div className="bars">
                        {week.map((b) => (
                            // data-driven height: the one allowed inline style (a CSS variable)
                            // eslint-disable-next-line react/forbid-dom-props
                            <div key={b.day} className="bar" style={{ '--bar-h': `${b.value}%` }} />
                        ))}
                    </div>
                    <div className="bar-labels">
                        {week.map((b) => (
                            <span key={b.day}>{b.day}</span>
                        ))}
                    </div>
                </ChartBox>

                <ChartBox title="Recent Activity">
                    <div className="alist">
                        {activity.map((a) => (
                            <div key={a.text} className="alist-item">
                                <div className={cx('alist-dot', `alist-dot-${a.tone}`)} />
                                <div>
                                    <div className="alist-text">{a.text}</div>
                                    <div className="alist-time">{a.time}</div>
                                </div>
                            </div>
                        ))}
                    </div>
                </ChartBox>
            </div>

            <div className="alert-row">
                {alerts.map((a) => (
                    <div key={a.text} className="alert-card">
                        <Tag tone={a.tone}>{a.tag}</Tag>
                        {a.text}
                    </div>
                ))}
            </div>
        </div>
    );
}
