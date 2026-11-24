<?php

namespace Tests\Feature\Database;

use App\Models\Platform;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyMarkazUserIdRemovalMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_22_232126_drop_markaz_user_id_from_transactions_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_final_schema_does_not_contain_markaz_user_id(): void
    {
        $this->assertFalse(Schema::hasColumn('transactions', 'markaz_user_id'));
    }

    public function test_removing_legacy_column_preserves_transactions_and_promo_usages(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();

        $platform = Platform::factory()->create();
        $transaction = Transaction::factory()->forPlatform($platform)->create();
        DB::table('transactions')->where('id', $transaction->id)->update(['markaz_user_id' => 445566]);

        $usageId = DB::table('transaction_promo_codes')->insertGetId([
            'transaction_id' => $transaction->id,
            'raw_code' => 'CPNS26',
            'code_position' => 1,
            'platform_id_snapshot' => $platform->id,
            'code_snapshot' => 'CPNS26',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('transactions', 'markaz_user_id'));
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'trx_id' => $transaction->trx_id]);
        $this->assertDatabaseHas('transaction_promo_codes', ['id' => $usageId, 'transaction_id' => $transaction->id]);
    }
}
