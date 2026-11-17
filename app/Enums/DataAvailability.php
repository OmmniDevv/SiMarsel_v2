<?php

namespace App\Enums;

/**
 * Whether a reported value is known: complete data, data up to a partial cutoff, or not known yet.
 */
enum DataAvailability: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
}
