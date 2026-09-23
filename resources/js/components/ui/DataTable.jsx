import Pagination, { visitPage } from './Pagination';
import { cx } from '@/lib/format';

/**
 * `.rgrid` table + footer. Rows must be JsonResource output (`id` = uuid).
 *
 * columns: [{ key, label, align: 'right'|'center', className, render: (row) => node }]
 * meta:    Laravel paginator meta → "Showing a–b of n {noun}" + pager (server-side, the normal case)
 * page / lastPage / onPageChange / total: client-side paging for small in-memory lists
 * stack:   under 640px show each row as a stacked card (use on high-traffic lists)
 */
export default function DataTable({
    columns,
    rows,
    meta,
    noun = 'records',
    empty = 'No records found',
    onRowClick,
    rowClassName,
    page,
    lastPage,
    onPageChange = visitPage,
    total,
    stack = false,
    footer = true,
}) {
    const alignClass = (c) => (c.align === 'right' ? 'text-right' : c.align === 'center' ? 'text-center' : '');

    let showing = `${total ?? rows.length} ${noun}`;
    if (meta) {
        showing = meta.total === 0 ? `No ${noun}` : `Showing ${meta.from}–${meta.to} of ${meta.total} ${noun}`;
    }

    return (
        <div className={cx('rgrid-wrap', stack && 'rgrid-stack')}>
            <table className="rgrid">
                <thead>
                    <tr>
                        {columns.map((c) => (
                            <th key={c.key} className={cx(alignClass(c), c.headerClassName)}>
                                {c.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 ? (
                        <tr>
                            <td colSpan={columns.length} className="products-empty">
                                {empty}
                            </td>
                        </tr>
                    ) : (
                        rows.map((row) => (
                            <tr
                                key={row.id}
                                className={cx(onRowClick && 'rgrid-clickable', rowClassName?.(row))}
                                onClick={onRowClick ? () => onRowClick(row) : undefined}
                            >
                                {columns.map((c) => (
                                    <td key={c.key} className={cx(alignClass(c), c.className)} data-label={c.label}>
                                        {c.render ? c.render(row) : row[c.key]}
                                    </td>
                                ))}
                            </tr>
                        ))
                    )}
                </tbody>
            </table>

            {footer && (
                <div className="rgrid-footer">
                    <span>{showing}</span>
                    <Pagination meta={meta} page={page} lastPage={lastPage} onChange={onPageChange} />
                </div>
            )}
        </div>
    );
}
