<?php

namespace App\Http\Resources;

use App\Models\Division;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An agenda on the Calendar: its general information only. division and target_divisions both name the division
 * of an internal agenda; both are empty for a company-wide agenda.
 */
class CalendarEventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'division' => $this->division ? $this->divisionData($this->division) : null,
            'target_divisions' => $this->division ? [$this->divisionData($this->division)] : [],
            'start_date' => $this->start_at->toDateString(),
            'end_date' => $this->end_at?->toDateString(),
            'description' => $this->description,
            // Agendas are not linked to tasks (ERD); the keys stay so the response shape is unchanged.
            'task_id' => null,
            'can_view_detail' => false,
        ];
    }

    /**
     * @return array{code_division: string, name: string}
     */
    private function divisionData(Division $division): array
    {
        return ['code_division' => $division->code_division, 'name' => $division->name];
    }
}
