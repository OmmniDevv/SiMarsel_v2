<?php

namespace Tests\Feature\Database;

use App\Enums\MarkazTransactionStatus;
use App\Models\Transaction;
use Tests\TestCase;

class TransactionReportStatusTest extends TestCase
{
    private const BACKFILL_MIGRATION = 'database/migrations/2026_11_22_234221_backfill_source_status_from_status_message.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_report_status_prefers_effective_status_and_never_falls_back_to_status_message(): void
    {
        $sourceSuccess = Transaction::factory()->create([
            'source_status' => MarkazTransactionStatus::Success->value,
            'effective_status' => null,
            'status_message' => MarkazTransactionStatus::Failed->label(),
        ]);
        $effectivePending = Transaction::factory()->create([
            'source_status' => MarkazTransactionStatus::Success->value,
            'effective_status' => MarkazTransactionStatus::Pending->value,
            'status_message' => MarkazTransactionStatus::Success->label(),
        ]);
        $unknown = Transaction::factory()->create([
            'source_status' => null,
            'effective_status' => null,
            'status_message' => MarkazTransactionStatus::Success->label(),
        ]);

        $this->assertSame(MarkazTransactionStatus::Success, $sourceSuccess->reportStatus());
        $this->assertSame(MarkazTransactionStatus::Pending, $effectivePending->reportStatus());
        $this->assertNull($unknown->reportStatus());
        $this->assertSame([$sourceSuccess->id], Transaction::query()->successful()->pluck('id')->all());
    }

    public function test_backfill_maps_normalized_legacy_labels_without_overwriting_known_source_status(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::BACKFILL_MIGRATION])->assertSuccessful();

        $success = Transaction::factory()->create([
            'source_status' => null,
            'effective_status' => null,
            'status_message' => ' SuCcEsS ',
        ]);
        $canceled = Transaction::factory()->create([
            'source_status' => null,
            'effective_status' => null,
            'status_message' => 'cancelled',
        ]);
        $unknown = Transaction::factory()->create([
            'source_status' => null,
            'effective_status' => null,
            'status_message' => 'not-a-status',
        ]);
        $known = Transaction::factory()->create([
            'source_status' => MarkazTransactionStatus::Failed->value,
            'effective_status' => null,
            'status_message' => MarkazTransactionStatus::Success->label(),
        ]);

        $this->artisan('migrate', ['--path' => self::BACKFILL_MIGRATION])->assertSuccessful();

        $this->assertSame(MarkazTransactionStatus::Success->value, $success->fresh()->source_status);
        $this->assertSame(MarkazTransactionStatus::Canceled->value, $canceled->fresh()->source_status);
        $this->assertNull($unknown->fresh()->source_status);
        $this->assertSame(MarkazTransactionStatus::Failed->value, $known->fresh()->source_status);
    }
}
