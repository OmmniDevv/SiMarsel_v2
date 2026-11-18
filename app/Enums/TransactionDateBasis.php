<?php

namespace App\Enums;

/**
 * Which transaction time puts a checkout into a date group.
 */
enum TransactionDateBasis: string
{
    /** transactions.markaz_created_at: Total, Konversi, and the status breakdown. */
    case Created = 'created';

    /** transactions.paid_date + paid_time: revenue, successful payments, AOV, and Pembeli Unik. */
    case Paid = 'paid';
}
