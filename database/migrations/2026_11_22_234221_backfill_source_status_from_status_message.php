<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $labels = [
            0 => ['pending'],
            1 => ['success'],
            2 => ['expired'],
            3 => ['failed'],
            4 => ['canceled', 'cancelled'],
        ];

        foreach ($labels as $status => $names) {
            DB::table('transactions')
                ->whereNull('source_status')
                ->whereIn(DB::raw('LOWER(TRIM(status_message))'), $names)
                ->update(['source_status' => $status]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The original source_status cannot be distinguished safely from a backfilled value.
    }
};
