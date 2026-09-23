import Corners from './Corners';
import { cx } from '@/lib/format';

export function StatGrid({ className, children }) {
    return <div className={cx('stat-grid', className)}>{children}</div>;
}

/** Line-drawn stat card with corner marks. tone of the sub line: accent | neutral | danger */
export default function StatCard({ label, value, sub, tone = 'accent' }) {
    return (
        <div className="scard">
            <Corners />
            <div className="scard-label">{label}</div>
            <div className="scard-val">{value}</div>
            {sub && <div className={cx('scard-sub', tone !== 'accent' && `scard-sub-${tone}`)}>{sub}</div>}
        </div>
    );
}
