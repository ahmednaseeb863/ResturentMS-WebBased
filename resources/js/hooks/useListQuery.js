import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * Server-side list filters kept in the URL (tab, search, status…).
 *
 *   const [query, setQuery] = useListQuery(filters);
 *   <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} />
 *
 * Changing a filter reloads the current page with the new query string (back to
 * page 1). Text search is debounced.
 */
export default function useListQuery(initial, { debounce = 300 } = {}) {
    const [query, setLocal] = useState(initial);
    const timer = useRef(null);

    useEffect(() => () => clearTimeout(timer.current), []);

    function visit(next) {
        const params = Object.fromEntries(
            Object.entries(next).filter(([, v]) => v !== '' && v !== null && v !== undefined),
        );
        router.get(window.location.pathname, params, { preserveState: true, preserveScroll: true, replace: true });
    }

    function setQuery(key, value) {
        const next = { ...query, [key]: value };
        setLocal(next);
        clearTimeout(timer.current);

        if (key === 'search') {
            timer.current = setTimeout(() => visit(next), debounce);
        } else {
            visit(next);
        }
    }

    return [query, setQuery];
}
