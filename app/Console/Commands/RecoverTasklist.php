<?php

namespace App\Console\Commands;

use App\Services\TasklistRecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:recovery
    {action=status : status, to-legacy, or from-legacy}
    {--apply : Write the changes instead of only reporting them}')]
#[Description('Choose and run the TASK-19 recovery procedure without losing data written after the deploy')]
class RecoverTasklist extends Command
{
    public function handle(TasklistRecoveryService $recovery): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status($recovery),
            'to-legacy' => $this->sync('Ke struktur lama (sebelum menjalankan aplikasi lama)', $recovery->toLegacy((bool) $this->option('apply'))),
            'from-legacy' => $this->sync('Dari struktur lama (sebelum menjalankan aplikasi TASK-19 lagi)', $recovery->fromLegacy((bool) $this->option('apply'))),
            default => $this->invalidAction(),
        };
    }

    private function status(TasklistRecoveryService $recovery): int
    {
        $this->table(['Data baru sejak TASK-19', 'Jumlah'], $this->rows($recovery->newDataReport()));

        if ($recovery->hasNewData()) {
            $this->warn('Ada data yang tidak bisa dipulihkan dengan migrate:rollback. Jangan rollback skema.');
            $this->line('Pakai prosedur pemulihan: php artisan tasks:recovery to-legacy --apply, lalu jalankan aplikasi versi lama.');
        } else {
            $this->info('Belum ada data baru. Rollback aman: php artisan migrate:rollback --step=15 --force');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $result
     */
    private function sync(string $title, array $result): int
    {
        $this->line($title.':');
        $this->table(['Langkah', 'Jumlah'], $this->rows($result));
        $this->line($this->option('apply')
            ? 'Perubahan sudah disimpan.'
            : 'Mode laporan, tidak ada yang disimpan. Jalankan dengan --apply untuk menyimpan.');

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Aksi tidak dikenal. Pilih status, to-legacy, atau from-legacy.');

        return self::INVALID;
    }

    /**
     * @param  array<string, int>  $values
     * @return array<int, array{0: string, 1: int}>
     */
    private function rows(array $values): array
    {
        return collect($values)->map(fn (int $count, string $label) => [$label, $count])->values()->all();
    }
}
