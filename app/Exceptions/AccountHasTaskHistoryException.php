<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An account that appears in task history (as PIC, author, reviewer, actor, ...) cannot be deleted, so that history
 * keeps pointing at a real account. Such an account is deactivated instead.
 */
class AccountHasTaskHistoryException extends Exception
{
    public function __construct()
    {
        parent::__construct('Akun ini memiliki riwayat tugas dan tidak dapat dihapus. Nonaktifkan akun ini sebagai gantinya.');
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return false;
        }

        return response()->json(['message' => $this->getMessage()], 422);
    }
}
