import { Search } from 'lucide-react';
import { cx } from '@/lib/format';

/** Filter row above a list (pos-react `.filter-row`); `count` text sits on the right. */
export function FilterBar({ count, className, children }) {
    return (
        <div className={cx('filter-row', className)}>
            {children}
            {count && <span className="products-count">{count}</span>}
        </div>
    );
}

export function SearchInput({ value, onChange, placeholder = 'Search…', autoFocus }) {
    return (
        <label className="finput products-search">
            <Search size={12} strokeWidth={1.5} />
            <input
                type="search"
                placeholder={placeholder}
                value={value}
                autoFocus={autoFocus}
                onChange={(e) => onChange(e.target.value)}
            />
        </label>
    );
}

/** options: array of strings or { value, label } */
export function FilterSelect({ label, value, onChange, options }) {
    return (
        <>
            {label && <span className="flabel">{label}:</span>}
            <select className="fselect" value={value} onChange={(e) => onChange(e.target.value)}>
                {options.map((o) => {
                    const opt = typeof o === 'string' ? { value: o, label: o } : o;
                    return (
                        <option key={opt.value} value={opt.value}>
                            {opt.label}
                        </option>
                    );
                })}
            </select>
        </>
    );
}
