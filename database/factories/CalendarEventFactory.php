<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CalendarEvent;
use App\Models\Division;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    /**
     * Define the model's default state: a company-wide agenda covering whole days.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 weeks', '+2 weeks')->setTime(0, 0);

        return [
            'division_id' => null,
            'creator_id' => fn () => $this->defaultCreatorId(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'start_at' => $start,
            'end_at' => fake()->optional()->dateTimeBetween($start, (clone $start)->modify('+5 days'))?->setTime(0, 0),
        ];
    }

    /**
     * An agenda internal to the division: only its accounts, Admin, and Manager see it.
     */
    public function internalTo(Division $division): static
    {
        return $this->state(['division_id' => $division->id]);
    }

    /**
     * The first Admin account, created when there is none yet.
     */
    private function defaultCreatorId(): int
    {
        $admin = Account::whereHas('role', fn ($query) => $query->where('code_role', Role::ADMIN))->orderBy('id')->first();

        if ($admin !== null) {
            return $admin->id;
        }

        $role = Role::firstOrCreate(['code_role' => Role::ADMIN], ['name' => 'Admin']);

        return Account::create([
            'role_id' => $role->id,
            'fullname' => 'Agenda Admin',
            'username' => 'agenda.admin.'.fake()->unique()->numerify('####'),
            'password' => 'password',
        ])->id;
    }
}
