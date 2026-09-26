import { Link } from '@inertiajs/react';
import { date, dateTime, money } from '@/lib/format';

/** The pos-react "current shift" banner (`.shift-current-banner`) for an open shift. */
export default function ShiftBanner({ shift, href }) {
    const content = (
        <>
            <div className="shift-banner-left">
                <div className="shift-banner-dot" />
                <div>
                    <div className="shift-banner-title">
                        Shift {shift.code} — Open{shift.is_mine && ' (yours)'}
                        {shift.is_overdue && <span className="shift-overdue">Overdue</span>}
                    </div>
                    <div className="shift-banner-sub">
                        {shift.counter?.name}
                        {shift.type && ` · ${shift.type.name}`} · Started {dateTime(shift.opened_at)} · Cashier:{' '}
                        {shift.opened_by?.name}
                    </div>
                </div>
            </div>
            <div className="shift-banner-stats">
                <div className="shift-banner-stat">
                    <div className="shift-banner-stat-val">{date(shift.business_date)}</div>
                    <div className="shift-banner-stat-label">Business Day</div>
                </div>
                {shift.scheduled_end && (
                    <div className="shift-banner-stat">
                        <div className="shift-banner-stat-val">{dateTime(shift.scheduled_end)}</div>
                        <div className="shift-banner-stat-label">Due To Close</div>
                    </div>
                )}
                <div className="shift-banner-stat">
                    <div className="shift-banner-stat-val">{money(shift.opening_cash)}</div>
                    <div className="shift-banner-stat-label">Opening Cash</div>
                </div>
                <div className="shift-banner-stat">
                    <div className="shift-banner-stat-val">{shift.cash_visible ? money(shift.expected_cash) : 'Blind'}</div>
                    <div className="shift-banner-stat-label">Expected Cash</div>
                </div>
            </div>
        </>
    );

    return href ? (
        <Link href={href} className="shift-current-banner shift-banner-link">
            {content}
        </Link>
    ) : (
        <div className="shift-current-banner">{content}</div>
    );
}
