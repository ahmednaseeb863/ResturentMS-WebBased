import { History, PackagePlus } from 'lucide-react';
import { Button } from '@/components/ui';
import useCan from '@/hooks/useCan';

/** Row buttons on stock lists: Add stock + Ledger. */
export default function StockActions({ item, onAdd }) {
    const can = useCan();
    const stop = (fn) => (e) => {
        e.stopPropagation();
        fn?.();
    };

    return (
        <span className="cell-actions">
            {can('stock.add') && (
                <Button variant="ghost" icon={PackagePlus} className="btn-xs" onClick={stop(() => onAdd(item))}>
                    Add stock
                </Button>
            )}
            {can('stock-ledger.index') && (
                <Button
                    variant="ghost"
                    icon={History}
                    className="btn-xs"
                    href={route('stock-ledger.index', { kind: item.kind, item: item.id })}
                    onClick={stop()}
                >
                    Ledger
                </Button>
            )}
        </span>
    );
}
