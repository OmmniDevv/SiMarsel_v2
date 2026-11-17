<?php

namespace App\Http\Resources;

use App\Models\Division;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A task or agenda on the shared schedule. Only what places it on the schedule is sent, never a task's PIC,
 * description, evidence, or history: those stay behind the task detail and its access rule.
 *
 * division is the task's division or the internal agenda's division; target_divisions repeats the internal
 * agenda's division (empty for tasks and company-wide agendas). Agendas are not linked to tasks.
 */
class ScheduleItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isTask = $this->resource instanceof Task;
        $task = $isTask ? $this->resource : null;
        $division = $this->division;

        return [
            'id' => $this->id,
            'type' => $isTask ? 'task' : 'event',
            'title' => $this->title,
            'division' => $division ? $this->divisionData($division) : null,
            'target_divisions' => ! $isTask && $division ? [$this->divisionData($division)] : [],
            'start_date' => ($isTask ? $this->start_date : $this->start_at)?->toDateString(),
            'end_date' => ($isTask ? $this->deadline : $this->end_at)?->toDateString(),
            'status' => $isTask ? $this->status->value : null,
            'priority' => $isTask ? $this->priority->value : null,
            'task_id' => $task?->id,
            'can_view_detail' => $task !== null && $request->user()->can('view', $task),
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
