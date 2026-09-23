import { router } from '@inertiajs/react';

function pageNumbers(page, total) {
    if (total <= 5) return Array.from({ length: total }, (_, i) => i + 1);
    if (page <= 3) return [1, 2, 3, '…', total];
    if (page >= total - 2) return [1, '…', total - 2, total - 1, total];
    return [1, '…', page - 1, page, page + 1, '…', total];
}

/** Go to a page of the current URL, keeping all other query params (filters, tab). */
export function visitPage(page) {
    const params = Object.fromEntries(new URLSearchParams(window.location.search));
    router.get(window.location.pathname, { ...params, page }, { preserveState: true, preserveScroll: true });
}

/**
 * pos-react `.rgrid-pager`. Pass Laravel paginator `meta` (current_page, last_page),
 * or control it yourself with `page` / `lastPage` / `onChange`.
 */
export default function Pagination({ meta, page, lastPage, onChange = visitPage }) {
    const current = page ?? meta?.current_page ?? 1;
    const last = lastPage ?? meta?.last_page ?? 1;
    if (last <= 1) return null;

    const go = (n) => n !== current && n >= 1 && n <= last && onChange(n);

    return (
        <nav className="rgrid-pager" aria-label="Pagination">
            <span
                className={current === 1 ? 'disabled' : ''}
                onClick={() => go(current - 1)}
                role="button"
                aria-label="Previous page"
            >
                ‹
            </span>
            {pageNumbers(current, last).map((n, i) =>
                n === '…' ? (
                    <span key={`e-${i}`} className="ellipsis">
                        …
                    </span>
                ) : (
                    <span
                        key={n}
                        className={n === current ? 'active' : ''}
                        onClick={() => go(n)}
                        role="button"
                        aria-current={n === current ? 'page' : undefined}
                    >
                        {n}
                    </span>
                ),
            )}
            <span
                className={current === last ? 'disabled' : ''}
                onClick={() => go(current + 1)}
                role="button"
                aria-label="Next page"
            >
                ›
            </span>
        </nav>
    );
}
