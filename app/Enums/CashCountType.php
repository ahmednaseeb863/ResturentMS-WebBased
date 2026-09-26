<?php

namespace App\Enums;

/** When the notes & coins of a drawer were counted. */
enum CashCountType: string
{
    case Opening = 'opening';
    case Closing = 'closing';
}
