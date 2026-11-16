<?php

namespace Database\Factories;

use App\Models\CalendarEvent;
use App\Models\Division;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    /**
     * Define the model's default state: a shared agenda covering whole days.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 weeks', '+2 weeks')->setTime(0, 0);

        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'start_at' => $start,
            'end_at' => fake()->optional()->dateTimeBetween($start, (clone $start)->modify('+5 days'))?->setTime(0, 0),
            'is_shared' => true,
        ];
    }

    /**
     * An internal agenda meant for the given divisions; none leaves it visible to Admin only.
     *
     * @param  array<int, Division>  $divisions
     */
    public function internalFor(array $divisions): static
    {
        return $this->state(['is_shared' => false])
            ->afterCreating(fn (CalendarEvent $event) => $event->targetDivisions()->attach(
                array_map(fn (Division $division) => $division->id, $divisions),
            ));
    }
}
