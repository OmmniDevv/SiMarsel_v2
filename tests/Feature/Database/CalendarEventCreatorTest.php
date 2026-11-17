<?php

namespace Tests\Feature\Database;

use App\Exceptions\AccountHasTaskHistoryException;
use App\Models\Account;
use App\Models\CalendarEvent;
use App\Models\Role;
use App\Services\AccountService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ERD TO-BE v1.0: every agenda has a creator account (calendar_events.creator_id NOT NULL, references accounts.id).
 */
class CalendarEventCreatorTest extends TestCase
{
    use RefreshDatabase;

    private Account $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = $this->account(Role::ADMIN, 'admin');
    }

    public function test_an_agenda_without_a_creator_cannot_be_stored(): void
    {
        $this->expectException(QueryException::class);

        DB::table('calendar_events')->insert(['title' => 'Tanpa pembuat', 'creator_id' => null, 'start_at' => '2026-11-20 00:00:00']);
    }

    public function test_an_agenda_created_without_a_creator_through_the_model_is_rejected(): void
    {
        try {
            CalendarEvent::create(['title' => 'Tanpa pembuat', 'start_at' => '2026-11-20']);
            $this->fail('An agenda without a creator was stored.');
        } catch (QueryException) {
            $this->assertSame(0, CalendarEvent::withTrashed()->count());
        }
    }

    public function test_an_agenda_with_an_unknown_creator_cannot_be_stored(): void
    {
        $this->expectException(QueryException::class);

        CalendarEvent::create(['title' => 'Pembuat tidak dikenal', 'creator_id' => 999999, 'start_at' => '2026-11-20']);
    }

    public function test_an_agenda_with_a_creator_is_stored(): void
    {
        $agenda = CalendarEvent::create(['title' => 'Rapat', 'creator_id' => $this->admin->id, 'start_at' => '2026-11-20']);

        $this->assertTrue($agenda->creator->is($this->admin));
    }

    public function test_the_creator_of_an_agenda_cannot_be_deleted_and_keeps_the_agenda_valid(): void
    {
        $creator = $this->account(Role::MANAGER, 'manager');
        $agenda = CalendarEvent::factory()->create(['creator_id' => $creator->id]);

        try {
            app(AccountService::class)->delete($creator, $this->admin);
            $this->fail('The creator of an agenda was deleted.');
        } catch (AccountHasTaskHistoryException) {
            $this->assertModelExists($creator);
            $this->assertSame($creator->id, $agenda->fresh()->creator_id);
        }
    }

    private function account(string $role, string $username): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'fullname' => $username,
            'username' => "agenda.{$username}",
            'password' => 'Password123!',
        ]);
    }
}
