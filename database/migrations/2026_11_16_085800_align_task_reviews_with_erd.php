<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * task_reviews as in the ERD: decision is varchar(30), the row has updated_at, and the review cycle is no longer
 * stored. The API still returns cycle, counted from the order of a task's reviews (TaskReview::scopeWithCycle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_reviews', function (Blueprint $table) {
            $table->string('decision', 30)->change();
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        DB::table('task_reviews')->update(['updated_at' => DB::raw('created_at')]);

        Schema::table('task_reviews', function (Blueprint $table) {
            $table->dropColumn('cycle');
        });
    }

    public function down(): void
    {
        Schema::table('task_reviews', function (Blueprint $table) {
            $table->unsignedSmallInteger('cycle')->nullable()->after('reviewer_id');
        });

        // Rebuild cycle the way the API counts it: per task, in the order the reviews were recorded.
        DB::table('task_reviews')->orderBy('task_id')->orderBy('created_at')->orderBy('id')
            ->get(['id', 'task_id'])
            ->groupBy('task_id')
            ->each(fn ($reviews) => $reviews->values()->each(
                fn (object $review, int $index) => DB::table('task_reviews')->where('id', $review->id)->update(['cycle' => $index + 1]),
            ));

        Schema::table('task_reviews', function (Blueprint $table) {
            $table->unsignedSmallInteger('cycle')->nullable(false)->change();
            $table->dropColumn('updated_at');
            $table->string('decision', 20)->change();
        });
    }
};
