<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeliveryZoneRequest;
use App\Http\Resources\DeliveryZoneResource;
use App\Models\DeliveryZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Delivery zones of the current branch (fee + minimum order, PLAN §4.14). */
class DeliveryZoneController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'delivery-zones.restore');
        $search = trim((string) $request->query('search'));

        $zones = $this->applyTab(DeliveryZone::query(), $tab)
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->withCount('deliveries')
            ->ordered()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('delivery-zones/Index', [
            'zones' => DeliveryZoneResource::collection($zones),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(DeliveryZone::class),
            'rules' => [
                'use_zones' => (bool) setting('delivery.use_zones'),
                'default_fee' => (float) setting('delivery.default_fee'),
                'min_order_amount' => (float) setting('delivery.min_order_amount'),
            ],
        ]);
    }

    public function store(DeliveryZoneRequest $request): RedirectResponse
    {
        $zone = DeliveryZone::create($request->zoneData());

        return back()->with('success', "Zone “{$zone->name}” added.");
    }

    public function update(DeliveryZoneRequest $request, DeliveryZone $deliveryZone): RedirectResponse
    {
        $deliveryZone->update($request->zoneData());

        return back()->with('success', "Zone “{$deliveryZone->name}” saved.");
    }

    public function destroy(Request $request, DeliveryZone $deliveryZone): RedirectResponse
    {
        $deliveryZone->trash($this->trashReason($request));

        return back()->with('success', "Zone “{$deliveryZone->name}” moved to trash.");
    }

    public function restore(DeliveryZone $deliveryZone): RedirectResponse
    {
        $deliveryZone->restoreFromTrash();

        return back()->with('success', "Zone “{$deliveryZone->name}” restored.");
    }
}
