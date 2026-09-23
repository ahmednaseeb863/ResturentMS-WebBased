import Corners from './Corners';
import { cx } from '@/lib/format';

/** Bordered panel with corner marks and a condensed title (pos-react `.chart-box`). */
export default function ChartBox({ title, actions, className, children }) {
    return (
        <div className={cx('chart-box', className)}>
            <Corners />
            {(title || actions) && (
                <div className="chart-box-head">
                    {title && <div className="chart-title">{title}</div>}
                    {actions}
                </div>
            )}
            {children}
        </div>
    );
}
