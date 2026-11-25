<?php

namespace App\Console\Commands;

use App\Services\TasklistRecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:recovery')]
#[Description('Report whether rolling back the Tasklist schema would lose current data')]
class RecoverTasklist extends Command
{
    public function handle(TasklistRecoveryService $recovery): int
    {
        $report = $recovery->newDataReport();

        $this->table(
            ['Data yang bergantung pada schema Tasklist saat ini', 'Jumlah'],
            collect($report)->map(fn (int $count, string $label) => [$label, $count])->values()->all(),
        );

        if ($recovery->hasNewData()) {
            $this->warn('Rollback schema akan menghilangkan data. Gunakan backup database untuk pemulihan; jangan jalankan rollback migration Tasklist.');

            return self::SUCCESS;
        }

        $this->info("Belum ada data yang bergantung pada schema saat ini. Rollback aman: php artisan migrate:rollback --step={$recovery->rollbackSteps()} --force");

        return self::SUCCESS;
    }
}
