import { Link } from '@inertiajs/react';
import { BarChart2 } from 'lucide-react';
import { EmptyState, PageStatus, PageToolbar } from '@/components/ui';
import reportIcon from './icons';

/** Reports screen (pos-react Reports): one section per report group the admin may open. */
export default function ReportsIndex({ groups }) {
    const count = groups.reduce((n, g) => n + g.reports.length, 0);

    return (
        <div className="rpt-page">
            <PageToolbar title="Reports" />
            <PageStatus>
                <span>{count} reports · by business day · Excel & PDF</span>
            </PageStatus>

            {groups.length === 0 && <EmptyState icon={BarChart2} title="No reports">Your role doesn’t include any report group.</EmptyState>}

            {groups.map((group) => (
                <section key={group.key} className="rpt-section">
                    <div className="section-title">{group.title}</div>
                    <div className="rpt-grid">
                        {group.reports.map((r) => {
                            const Icon = reportIcon(r.icon);
                            return (
                                <Link key={r.key} href={r.url} className="rpt-card">
                                    <div className="rpt-icon">
                                        <Icon strokeWidth={1.5} />
                                    </div>
                                    <div className="rpt-title">{r.title}</div>
                                    <div className="rpt-desc">{r.description}</div>
                                </Link>
                            );
                        })}
                    </div>
                </section>
            ))}
        </div>
    );
}
