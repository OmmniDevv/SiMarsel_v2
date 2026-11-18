<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\CalendarEvent;
use App\Models\Division;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class CalendarEventSeeder extends Seeder
{
    /**
     * Company-wide agendas: title => [days from this Monday to the start, length in days].
     */
    public const SHARED_AGENDAS = [
        'Rapat koordinasi bulanan' => [0, 1],
        'Live Instagram promo akhir bulan' => [3, 1],
        'Libur cuti bersama' => [9, 2],
        'Evaluasi kampanye kuartal' => [15, 3],
    ];

    /**
     * Demo agendas around the current week, so the Timeline has something to show locally: the company-wide ones
     * and an internal briefing per division. They are credited to the first Admin account.
     */
    public function run(): void
    {
        $creator = Account::whereHas('role', fn ($query) => $query->where('code_role', Role::ADMIN))->orderBy('id')->first();

        if ($creator === null || CalendarEvent::query()->exists()) {
            return;
        }

        $monday = CarbonImmutable::today()->startOfWeek();

        foreach (self::SHARED_AGENDAS as $title => [$offset, $days]) {
            CalendarEvent::create([
                'creator_id' => $creator->id,
                'title' => $title,
                'is_shared' => true,
                'start_at' => $monday->addDays($offset),
                'end_at' => $days > 1 ? $monday->addDays($offset + $days - 1) : null,
            ]);
        }

        Division::query()->orderBy('id')->get()->each(fn (Division $division, int $index) => CalendarEvent::create([
            'division_id' => $division->id,
            'creator_id' => $creator->id,
            'title' => "Briefing internal {$division->name}",
            'is_shared' => false,
            'start_at' => $monday->addDays(1 + $index % 5),
        ])->targetDivisions()->attach($division->id));
    }
}
