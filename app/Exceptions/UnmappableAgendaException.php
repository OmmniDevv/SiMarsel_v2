<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Old agendas that cannot be stored in calendar_events: an internal agenda with no division to show it to, or an
 * agenda without a creator while no Admin account exists.
 */
class UnmappableAgendaException extends RuntimeException
{
    /**
     * @param  array<int, string>  $problems  Keyed by schedule_events id.
     */
    public static function forAgendas(array $problems): self
    {
        $list = collect($problems)->map(fn (string $problem, int $id) => "#{$id} {$problem}")->implode('; ');

        return new self(
            "Agenda lama tidak bisa dipindahkan ke calendar_events ({$list}). Lengkapi divisi penyelenggara atau "
            .'divisi target agenda tersebut di schedule_events, atau buat akun Admin, lalu jalankan ulang.'
        );
    }
}
