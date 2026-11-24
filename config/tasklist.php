<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Destructive rollback of the TASK-19 migrations
    |--------------------------------------------------------------------------
    |
    | migrate:rollback of the TASK-19 migrations is refused once the application has written data the old schema
    | cannot hold (PIC assignments, comments, new agendas, ...). Use `php artisan tasks:recovery` to inspect the
    | risk. Set this to true only to throw that data away on purpose, for example on a disposable test database.
    |
    */

    'allow_destructive_rollback' => (bool) env('TASKLIST_ALLOW_DESTRUCTIVE_ROLLBACK', false),

];
