<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\PromoCodeUsageService;

class TransactionObserver
{
    public function __construct(private PromoCodeUsageService $promoCodeUsageService) {}

    /**
     * Normalize promo usages whenever attribution input or the report status changes.
     */
    public function saved(Transaction $transaction): void
    {
        if ($transaction->wasRecentlyCreated || $transaction->wasChanged([
            'promo_code',
            'platform_id',
            'paid_date',
            'paid_time',
            'source_status',
            'effective_status',
        ])) {
            $this->promoCodeUsageService->normalize($transaction);
        }
    }
}
