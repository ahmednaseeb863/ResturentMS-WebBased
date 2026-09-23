import { forwardRef } from 'react';
import { cx } from '@/lib/format';

/** Two-column form grid (1 column on phones). */
export function FormGrid({ className, children }) {
    return <div className={cx('cust-form-grid', className)}>{children}</div>;
}

/** Heading that splits a FormGrid into parts (pos-react `.section-title`). */
export function FormSection({ icon: Icon, title, children }) {
    return (
        <div className="cust-field-full form-section">
            <div className="section-title">
                {Icon && <Icon />}
                {title}
            </div>
            {children && <div className="form-section-sub">{children}</div>}
        </div>
    );
}

/** Label + control + validation error (pos-react `.cust-field`). `full` spans both columns. */
export function Field({ label, required, error, hint, full, className, children }) {
    return (
        <div className={cx('cust-field', full && 'cust-field-full', className)}>
            {label && (
                <label>
                    {label}
                    {required && <span className="cust-required">*</span>}
                </label>
            )}
            {children}
            {hint && !error && <div className="field-hint">{hint}</div>}
            {error && <div className="field-error">{error}</div>}
        </div>
    );
}

export const Input = forwardRef(function Input({ mono, invalid, className, ...props }, ref) {
    return (
        <input ref={ref} className={cx('cust-input', mono && 'mono', invalid && 'is-invalid', className)} {...props} />
    );
});

/** options: array of strings or { value, label }; `placeholder` adds an empty first option. */
export const Select = forwardRef(function Select({ options = [], placeholder, invalid, className, ...props }, ref) {
    return (
        <select ref={ref} className={cx('cust-input', invalid && 'is-invalid', className)} {...props}>
            {placeholder !== undefined && <option value="">{placeholder}</option>}
            {options.map((o) => {
                const opt = typeof o === 'string' ? { value: o, label: o } : o;
                return (
                    <option key={opt.value} value={opt.value}>
                        {opt.label}
                    </option>
                );
            })}
        </select>
    );
});

export const Textarea = forwardRef(function Textarea({ invalid, className, ...props }, ref) {
    return (
        <textarea ref={ref} className={cx('cust-input cust-textarea', invalid && 'is-invalid', className)} {...props} />
    );
});
