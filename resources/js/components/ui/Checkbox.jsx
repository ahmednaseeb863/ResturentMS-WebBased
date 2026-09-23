import { cx } from '@/lib/format';

/** pos-react `.role-checkbox` box. `partial` shows the dash (some children ticked). */
export function CheckBox({ checked, partial }) {
    return (
        <span className={cx('role-checkbox', checked ? 'checked' : partial && 'partial')} aria-hidden="true">
            {checked && (
                <svg
                    width="10"
                    height="10"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="white"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    <polyline points="20 6 9 17 4 12" />
                </svg>
            )}
            {!checked && partial && <span className="role-checkbox-dash" />}
        </span>
    );
}

/** Clickable tick row (pos-react `.role-perm-item`). */
export function CheckItem({ checked, onChange, disabled, className, children }) {
    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={checked}
            disabled={disabled}
            className={cx('role-perm-item', className)}
            onClick={() => onChange(!checked)}
        >
            <CheckBox checked={checked} />
            <span>{children}</span>
        </button>
    );
}
