<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migrated schema against the Tasklist ERD TO-BE v1.0 (docs/database/tasklist-erd-to-be-v1.dbml): every ERD
 * column with its nullability (and type on MySQL), index, unique key, and reference exists. Columns the ERD does
 * not list are allowed only when they are a documented deviation (DEVIATIONS); the agenda target divisions table is
 * one too.
 */
class TasklistErdConformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ERD columns per table: name => [type, nullable].
     *
     * @var array<string, array<string, array{0: string, 1: bool}>>
     */
    private const COLUMNS = [
        'roles' => ['id' => ['bigint', false], 'code_role' => ['varchar(25)', false], 'name' => ['varchar(50)', false], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true]],
        'permissions' => ['id' => ['bigint', false], 'code' => ['varchar(80)', false], 'description' => ['text', true], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true]],
        'role_permissions' => ['role_id' => ['bigint', false], 'permission_id' => ['bigint', false]],
        'divisions' => ['id' => ['bigint', false], 'code_division' => ['varchar(50)', false], 'name' => ['varchar(100)', false], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true]],
        'accounts' => [
            'id' => ['bigint', false], 'role_id' => ['bigint', false], 'division_id' => ['bigint', true], 'fullname' => ['varchar(255)', false],
            'username' => ['varchar(255)', false], 'is_active' => ['boolean', false], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ],
        'platforms' => ['id' => ['bigint', false], 'slug' => ['varchar(50)', false], 'name' => ['varchar(100)', false], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true]],
        'task_categories' => [
            'id' => ['bigint', false], 'name' => ['varchar(100)', false], 'requires_review' => ['boolean', false], 'evidence_required' => ['boolean', false],
            'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ],
        'tasks' => [
            'id' => ['bigint', false], 'title' => ['varchar(150)', false], 'description' => ['text', true], 'category_id' => ['bigint', true],
            'division_id' => ['bigint', false], 'platform_id' => ['bigint', true], 'creator_id' => ['bigint', true], 'priority' => ['varchar(10)', false],
            'status' => ['varchar(20)', false], 'start_date' => ['date', true], 'deadline' => ['date', true], 'requires_review' => ['boolean', false],
            'evidence_required' => ['boolean', false], 'completed_at' => ['timestamp', true], 'version' => ['int', false], 'deleted_at' => ['timestamp', true],
            'deleted_by' => ['bigint', true], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ],
        'task_divisions' => ['task_id' => ['bigint', false], 'division_id' => ['bigint', false], 'added_by' => ['bigint', true], 'created_at' => ['timestamp', true]],
        'task_assignments' => [
            'id' => ['bigint', false], 'task_id' => ['bigint', false], 'account_id' => ['bigint', false], 'assigned_by' => ['bigint', true],
            'assigned_at' => ['timestamp', false], 'unassigned_by' => ['bigint', true], 'unassigned_at' => ['timestamp', true], 'note' => ['text', true],
            'active_flag' => ['tinyint', true],
        ],
        'task_evidences' => [
            'id' => ['bigint', false], 'task_id' => ['bigint', false], 'submitter_id' => ['bigint', true], 'type' => ['varchar(10)', false],
            'file_path' => ['varchar(255)', true], 'file_name' => ['varchar(255)', true], 'mime_type' => ['varchar(100)', true], 'file_size' => ['bigint', true],
            'url' => ['text', true], 'note' => ['text', true], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
            'deleted_at' => ['timestamp', true], 'deleted_by' => ['bigint', true],
        ],
        'task_reviews' => [
            'id' => ['bigint', false], 'task_id' => ['bigint', false], 'reviewer_id' => ['bigint', true], 'decision' => ['varchar(30)', false],
            'note' => ['text', true], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ],
        'task_comments' => [
            'id' => ['bigint', false], 'task_id' => ['bigint', false], 'author_id' => ['bigint', false], 'parent_comment_id' => ['bigint', true],
            'content' => ['text', false], 'created_at' => ['timestamp', false], 'updated_at' => ['timestamp', true], 'deleted_at' => ['timestamp', true],
        ],
        'task_activities' => [
            'id' => ['bigint', false], 'task_id' => ['bigint', false], 'account_id' => ['bigint', true], 'action' => ['varchar(50)', false],
            'changes' => ['json', true], 'created_at' => ['timestamp', false],
        ],
        'calendar_events' => [
            'id' => ['bigint', false], 'division_id' => ['bigint', true], 'creator_id' => ['bigint', false], 'title' => ['varchar(255)', false],
            'description' => ['text', true], 'start_at' => ['datetime', false], 'end_at' => ['datetime', true], 'created_at' => ['timestamp', true],
            'updated_at' => ['timestamp', true], 'deleted_at' => ['timestamp', true],
        ],
    ];

    /**
     * Columns the ERD does not list but the application still requires.
     *
     * @var array<string, array<int, string>>
     */
    private const DEVIATIONS = [
        'accounts' => ['password'],                 // "Kolom autentikasi lain tidak ditampilkan"
        'task_activities' => ['actor_name_snapshot'], // existing before TASK-19: name of a deleted actor
        'calendar_events' => ['is_shared'],         // agenda access of the existing Schedule/Calendar (PR #115 review)
    ];

    /**
     * ERD indexes and unique keys: table => [[columns, unique]].
     *
     * @var array<string, array<int, array{0: array<int, string>, 1: bool}>>
     */
    private const INDEXES = [
        'roles' => [[['code_role'], true]],
        'permissions' => [[['code'], true]],
        'role_permissions' => [[['role_id', 'permission_id'], true]],
        'divisions' => [[['code_division'], true]],
        'accounts' => [[['username'], true]],
        'platforms' => [[['slug'], true]],
        'task_categories' => [[['name'], true]],
        'tasks' => [[['division_id', 'status'], false], [['status', 'deadline'], false], [['start_date'], false], [['deleted_at'], false]],
        'task_divisions' => [[['task_id', 'division_id'], true]],
        'task_assignments' => [[['task_id', 'account_id', 'active_flag'], true], [['task_id', 'unassigned_at'], false], [['account_id', 'unassigned_at'], false]],
        'task_evidences' => [[['task_id', 'created_at'], false]],
        'task_reviews' => [[['task_id', 'created_at'], false]],
        'task_comments' => [[['task_id', 'created_at'], false], [['parent_comment_id'], false]],
        'task_activities' => [[['task_id', 'created_at'], false]],
        'calendar_events' => [[['start_at'], false]],
    ];

    /**
     * ERD references: "table.column" => "table.column".
     */
    private const REFERENCES = [
        'accounts.role_id' => 'roles.id', 'accounts.division_id' => 'divisions.id',
        'role_permissions.role_id' => 'roles.id', 'role_permissions.permission_id' => 'permissions.id',
        'tasks.category_id' => 'task_categories.id', 'tasks.division_id' => 'divisions.id', 'tasks.platform_id' => 'platforms.id',
        'tasks.creator_id' => 'accounts.id', 'tasks.deleted_by' => 'accounts.id',
        'task_divisions.task_id' => 'tasks.id', 'task_divisions.division_id' => 'divisions.id', 'task_divisions.added_by' => 'accounts.id',
        'task_assignments.task_id' => 'tasks.id', 'task_assignments.account_id' => 'accounts.id',
        'task_assignments.assigned_by' => 'accounts.id', 'task_assignments.unassigned_by' => 'accounts.id',
        'task_evidences.task_id' => 'tasks.id', 'task_evidences.submitter_id' => 'accounts.id', 'task_evidences.deleted_by' => 'accounts.id',
        'task_reviews.task_id' => 'tasks.id', 'task_reviews.reviewer_id' => 'accounts.id',
        'task_comments.task_id' => 'tasks.id', 'task_comments.author_id' => 'accounts.id', 'task_comments.parent_comment_id' => 'task_comments.id',
        'task_activities.task_id' => 'tasks.id', 'task_activities.account_id' => 'accounts.id',
        'calendar_events.division_id' => 'divisions.id', 'calendar_events.creator_id' => 'accounts.id',
    ];

    public function test_every_erd_column_exists_with_its_nullability_and_type(): void
    {
        foreach (self::COLUMNS as $table => $expected) {
            $columns = collect(Schema::getColumns($table))->keyBy('name');

            foreach ($expected as $name => [$type, $nullable]) {
                $this->assertTrue($columns->has($name), "{$table}.{$name} is missing");
                $this->assertSame($nullable, $columns[$name]['nullable'], "{$table}.{$name} nullable");

                if (DB::getDriverName() === 'mysql') {
                    $this->assertSame($type, $this->mysqlType($columns[$name]), "{$table}.{$name} type");
                }
            }

            $this->assertEqualsCanonicalizing(
                self::DEVIATIONS[$table] ?? [],
                $columns->keys()->diff(array_keys($expected))->values()->all(),
                "{$table} has columns the ERD does not list",
            );
        }
    }

    public function test_every_erd_index_and_unique_key_exists(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            $actual = collect(Schema::getIndexes($table));

            foreach ($indexes as [$columns, $unique]) {
                $this->assertTrue(
                    $actual->contains(fn (array $index) => $index['columns'] === $columns && (! $unique || $index['unique'])),
                    "{$table} (".implode(', ', $columns).')'.($unique ? ' unique' : '').' is missing',
                );
            }
        }
    }

    public function test_every_erd_reference_is_a_foreign_key(): void
    {
        foreach (self::REFERENCES as $from => $to) {
            [$table, $column] = explode('.', $from);
            [$foreignTable, $foreignColumn] = explode('.', $to);

            $this->assertTrue(
                collect(Schema::getForeignKeys($table))->contains(fn (array $key) => $key['columns'] === [$column]
                    && $key['foreign_table'] === $foreignTable && $key['foreign_columns'] === [$foreignColumn]),
                "{$from} > {$to} is missing",
            );
        }
    }

    /**
     * Beyond the ERD, kept so agendas keep the access rules of the existing Schedule/Calendar: is_shared and the
     * target divisions of an internal agenda. calendar_events has no task link.
     */
    public function test_the_agenda_deviations_from_the_erd_are_only_is_shared_and_the_target_divisions(): void
    {
        $isShared = collect(Schema::getColumns('calendar_events'))->firstWhere('name', 'is_shared');
        $this->assertFalse($isShared['nullable']);

        $this->assertSame(['calendar_event_id', 'division_id'], Schema::getColumnListing('calendar_event_divisions'));
        $this->assertTrue(collect(Schema::getIndexes('calendar_event_divisions'))
            ->contains(fn (array $index) => $index['columns'] === ['calendar_event_id', 'division_id'] && $index['unique']));
        $this->assertEqualsCanonicalizing(
            ['calendar_event_id>calendar_events.id', 'division_id>divisions.id'],
            collect(Schema::getForeignKeys('calendar_event_divisions'))
                ->map(fn (array $key) => $key['columns'][0].'>'.$key['foreign_table'].'.'.$key['foreign_columns'][0])->all(),
        );

        $this->assertSame(['calendar_events.division_id', 'calendar_events.creator_id'], collect(Schema::getForeignKeys('calendar_events'))
            ->map(fn (array $key) => 'calendar_events.'.$key['columns'][0])->sort(fn ($a, $b) => $b <=> $a)->values()->all());
    }

    /**
     * The column type in ERD terms: unsigned is ignored and tinyint(1) is boolean.
     *
     * @param  array{type: string, type_name: string}  $column
     */
    private function mysqlType(array $column): string
    {
        return match (true) {
            $column['type'] === 'tinyint(1)' => 'boolean',
            $column['type_name'] === 'varchar' => $column['type'],
            default => $column['type_name'],
        };
    }
}
