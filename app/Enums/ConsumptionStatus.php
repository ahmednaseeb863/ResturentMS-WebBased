<?php

namespace App\Enums;

/**
 * Raw materials of an order line: `pending` until the cook confirms them (kitchen phase),
 * `not_required` for ready items (their stock moves on sale) and lines without a recipe.
 */
enum ConsumptionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case AutoConfirmed = 'auto_confirmed';
    case NotRequired = 'not_required';
}
