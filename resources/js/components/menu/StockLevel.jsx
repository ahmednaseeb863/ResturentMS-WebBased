import { Tag } from '@/components/ui';
import { qty } from '@/lib/format';

/** "12.5 kg" with a LOW tag under the alert level. */
export default function StockLevel({ item }) {
    const unit = item.stock_unit?.short_name ?? '';
    return (
        <span className={item.is_low ? 'stock-level is-low' : 'stock-level'}>
            {qty(item.current_stock, unit)}
            {item.is_low && <Tag tone="warn">Low</Tag>}
        </span>
    );
}
