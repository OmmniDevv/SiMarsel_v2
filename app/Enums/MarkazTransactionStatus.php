<?php

namespace App\Enums;

/**
 * Transaction status codes sent by Markaz in the API "status" field.
 */
enum MarkazTransactionStatus: int
{
    case Pending = 0;
    case Success = 1;
    case Expired = 2;
    case Failed = 3;
    case Canceled = 4;

    public const UNAVAILABLE_LABEL = 'Status belum tersedia';

    /**
     * The status a report uses: effective_status when it is set (0 included), otherwise source_status.
     * Null means neither field is known yet, which is different from Pending.
     */
    public static function forReport(?int $effectiveStatus, ?int $sourceStatus): ?self
    {
        $code = $effectiveStatus ?? $sourceStatus;

        return $code === null ? null : self::from($code);
    }

    public static function labelFor(?self $status): string
    {
        return $status?->label() ?? self::UNAVAILABLE_LABEL;
    }

    /**
     * Expired, Failed, and Canceled form the "Tidak Berhasil" group.
     */
    public function isUnsuccessful(): bool
    {
        return in_array($this, [self::Expired, self::Failed, self::Canceled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Success => 'Success',
            self::Expired => 'Expired',
            self::Failed => 'Failed',
            self::Canceled => 'Canceled',
        };
    }
}
