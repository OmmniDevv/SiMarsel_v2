<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ID the frontend generates for each press of Send. A request sent again with the same ID is the same comment;
     * the unique key makes that hold even when both requests arrive at once. Comments stored before this column are
     * left without one (NULL never collides).
     */
    public function up(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->uuid('client_request_id')->nullable()->after('content');
            $table->unique(['author_id', 'client_request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropUnique(['author_id', 'client_request_id']);
            $table->dropColumn('client_request_id');
        });
    }
};
