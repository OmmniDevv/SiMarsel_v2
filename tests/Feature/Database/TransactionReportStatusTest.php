<?php

namespace Tests\Feature\Database;

use App\Enums\MarkazTransactionStatus;
use App\Models\Transaction;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransactionReportStatusTest extends TestCase
{
    private const DROP_STATUS_MESSAGE_MIGRATION = 'database/migrations/2026_11_23_083748_drop_status_message_from_transactions_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_report_status_prefers_effective_status_and_falls_back_to_source_status(): void
    {
        $sourceSuccess = Transaction::factory()->create([
            'source_status' => MarkazTransactionStatus::Success->value,
            'effective_status' => null,
        ]);
        $effectivePending = Transaction::factory()->create([
            'source_status' => MarkazTransactionStatus::Success->value,
            'effective_status' => MarkazTransactionStatus::Pending->value,
        ]);
        $unknown = Transaction::factory()->create([
            'source_status' => null,
            'effective_status' => null,
        ]);

        $this->assertSame(MarkazTransactionStatus::Success, $sourceSuccess->reportStatus());
        $this->assertSame(MarkazTransactionStatus::Pending, $effectivePending->reportStatus());
        $this->assertNull($unknown->reportStatus());
        $this->assertSame([$sourceSuccess->id], Transaction::query()->successful()->pluck('id')->all());
    }

    public function test_status_message_column_is_removed_and_the_migration_can_be_reversed(): void
    {
        $this->assertFalse(Schema::hasColumn('transactions', 'status_message'));

        $this->artisan('migrate:rollback', ['--path' => self::DROP_STATUS_MESSAGE_MIGRATION])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('transactions', 'status_message'));

        $this->artisan('migrate', ['--path' => self::DROP_STATUS_MESSAGE_MIGRATION])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('transactions', 'status_message'));
    }
}
