<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Phase 0: static demo data so the shell can be compared with pos-react.
     * Replaced by real branch / consolidated figures in Phase 15.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'demo' => true,
            'stats' => [
                ['label' => "Today's Sales", 'value' => 'Rs 184,250', 'sub' => '↑ 12% vs yesterday', 'tone' => 'accent'],
                ['label' => 'Orders', 'value' => '147', 'sub' => '92 dine-in · 38 takeaway · 17 delivery', 'tone' => 'accent'],
                ['label' => 'Open Tables', 'value' => '9 / 24', 'sub' => '3 waiting for bill', 'tone' => 'neutral'],
                ['label' => 'Low Stock Items', 'value' => '6', 'sub' => '2 critical', 'tone' => 'neutral'],
            ],
            'week' => [
                ['day' => 'Mon', 'value' => 55], ['day' => 'Tue', 'value' => 72], ['day' => 'Wed', 'value' => 48],
                ['day' => 'Thu', 'value' => 88], ['day' => 'Fri', 'value' => 64], ['day' => 'Sat', 'value' => 92],
                ['day' => 'Sun', 'value' => 78],
            ],
            'activity' => [
                ['text' => 'Order #1047 — Table 12 — Rs 4,850', 'time' => '2 min ago · Counter A', 'tone' => 'accent'],
                ['text' => 'Stock added — Chicken 25 kg', 'time' => '15 min ago · Kitchen store', 'tone' => 'neutral'],
                ['text' => 'Order #1041 voided — wrong item', 'time' => '1 hr ago', 'tone' => 'light'],
                ['text' => 'Shift opened — Night · Counter B', 'time' => '3 hrs ago', 'tone' => 'neutral'],
            ],
            'alerts' => [
                ['tag' => 'Low stock', 'tone' => 'accent', 'text' => 'Mozzarella — 1.2 kg left (min 5 kg)'],
                ['tag' => 'Kitchen', 'tone' => 'neutral', 'text' => '4 consumptions waiting for cook confirmation'],
            ],
        ]);
    }
}
