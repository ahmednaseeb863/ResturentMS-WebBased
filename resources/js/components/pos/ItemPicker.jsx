import { useMemo, useState } from 'react';
import { Gift, Package, Search, UtensilsCrossed } from 'lucide-react';
import { cx, money, qty } from '@/lib/format';

const ICONS = { menu_item: UtensilsCrossed, ready_item: Package, deal: Gift };

/**
 * Left side of the POS (pos-react `.pos-items`): search / scan, category tabs and the
 * item grid. Only items sold for the current order type are shown. Enter in the search
 * adds the item whose code / barcode matches, or the only item found.
 */
export default function ItemPicker({ items, deals, categories, orderType, onPick }) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('all');

    const forType = useMemo(
        () => [...deals, ...items].filter((i) => i.available_for.includes(orderType)),
        [items, deals, orderType],
    );

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();
        return forType.filter((i) => {
            if (q) return i.name.toLowerCase().includes(q) || (i.codes ?? []).some((c) => c.toLowerCase() === q);
            if (category === 'all') return i.type !== 'deal';
            if (category === 'deals') return i.type === 'deal';
            return i.category === category;
        });
    }, [forType, search, category]);

    const tabs = [
        { id: 'all', name: 'All' },
        ...(forType.some((i) => i.type === 'deal') ? [{ id: 'deals', name: 'Deals' }] : []),
        ...categories.filter((c) => forType.some((i) => i.category === c.id)),
    ];

    function onKeyDown(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const q = search.trim().toLowerCase();
        const exact = forType.find((i) => (i.codes ?? []).some((c) => c.toLowerCase() === q));
        const pick = exact ?? (shown.length === 1 ? shown[0] : null);
        if (pick && !pick.sold_out) {
            onPick(pick);
            setSearch('');
        }
    }

    return (
        <div className="pos-items">
            <label className="pos-search">
                <Search size={14} strokeWidth={1.5} />
                <input
                    className="pos-search-input"
                    type="search"
                    value={search}
                    placeholder="Search or scan barcode…"
                    aria-label="Search items"
                    onChange={(e) => setSearch(e.target.value)}
                    onKeyDown={onKeyDown}
                />
            </label>

            <div className="pos-cats" role="tablist" aria-label="Categories">
                {tabs.map((c) => (
                    <button
                        key={c.id}
                        type="button"
                        role="tab"
                        aria-selected={category === c.id && !search}
                        className={cx('pos-cat', category === c.id && !search && 'active')}
                        onClick={() => {
                            setCategory(c.id);
                            setSearch('');
                        }}
                    >
                        {c.name}
                    </button>
                ))}
            </div>

            {shown.length === 0 ? (
                <p className="pos-empty">{search ? `Nothing matches “${search}”.` : 'No items here for this order type.'}</p>
            ) : (
                <div className="pos-pgrid">
                    {shown.map((item) => (
                        <ItemCard key={item.key} item={item} onPick={onPick} />
                    ))}
                </div>
            )}
        </div>
    );
}

function ItemCard({ item, onPick }) {
    const Icon = ICONS[item.type];
    const out = item.type === 'ready_item' && item.stock <= 0;

    return (
        <button
            type="button"
            className={cx('pos-pcard', item.sold_out && 'is-sold-out')}
            disabled={item.sold_out}
            onClick={() => onPick(item)}
        >
            <div className="pos-picon">
                {item.image ? <img src={item.image} alt="" loading="lazy" /> : <Icon size={20} strokeWidth={1.5} />}
            </div>
            <div className="pos-pname">{item.name}</div>
            <div className="pos-pprice">
                {item.variants?.length ? 'from ' : ''}
                {money(item.variants?.length ? Math.min(...item.variants.map((v) => v.price)) : item.price)}
            </div>
            {item.type === 'ready_item' && (
                <div className={cx('pos-pstock', (item.low || out) && 'low')}>{out ? 'Out of stock' : `Stock: ${qty(item.stock)}`}</div>
            )}
            {item.type === 'deal' && <div className="pos-pstock">Deal</div>}
            {item.sold_out && <div className="pos-pstock low">Sold out</div>}
        </button>
    );
}
