<?php

namespace App\Console\Commands;

use App\Services\TasklistMigrationValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:validate-migration')]
#[Description('Check (read-only) that the TASK-19 Tasklist migrations were applied and left the data consistent')]
class ValidateTasklistMigration extends Command
{
    public function handle(TasklistMigrationValidator $validator): int
    {
        $results = $validator->validate();

        $this->table(['Pemeriksaan', 'Hasil', 'Detail'], array_map(fn (array $result) => array_values($result), $results));

        if (! $validator->passed($results)) {
            $this->error('Validasi gagal. Periksa baris FAIL sebelum melanjutkan deployment.');

            return self::FAILURE;
        }

        $this->info('Validasi lulus.');

        return self::SUCCESS;
    }
}
