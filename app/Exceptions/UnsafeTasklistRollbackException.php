<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Rolling back the TASK-19 migrations would delete data written after the deploy.
 */
class UnsafeTasklistRollbackException extends RuntimeException
{
    /**
     * @param  array<string, int>  $report
     */
    public static function withReport(array $report): self
    {
        $found = collect($report)->filter()->map(fn (int $count, string $kind) => "{$kind}: {$count}")->implode('; ');

        return new self(
            "Rollback TASK-19 dibatalkan karena akan menghapus data baru ({$found}). "
            .'Jalankan `php artisan tasks:recovery` untuk melihat rinciannya dan gunakan backup database untuk '
            .'pemulihan. Set TASKLIST_ALLOW_DESTRUCTIVE_ROLLBACK=true hanya bila data tersebut memang boleh dibuang.'
        );
    }
}
