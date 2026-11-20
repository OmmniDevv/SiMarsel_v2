<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Exceptions\CommentReplyOutsideTaskException;
use App\Models\Account;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskCommentService
{
    /**
     * A comment repeated by the same account on the same task and parent within this many seconds is taken to be the
     * same request sent again (double click or retry), not a new comment.
     */
    public const DUPLICATE_WINDOW_SECONDS = 10;

    private const RELATIONS = ['author.role', 'parent.author'];

    /**
     * Every comment and reply of the task, oldest first, with who wrote it and which comment it answers.
     *
     * @return Collection<int, TaskComment>
     */
    public function list(Task $task): Collection
    {
        return $task->comments()->with(self::RELATIONS)->get();
    }

    /**
     * Add a comment, or a reply when parent_comment_id is given, and record it in the task's history. The task is
     * read again under lock, so who may comment and its status are checked on the latest data, and a request sent
     * again shortly after returns the comment it already stored instead of adding a second one. A comment never
     * changes the task's status, review, or PICs.
     *
     * @param  array{content: string, parent_comment_id?: ?int}  $data
     *
     * @throws AuthorizationException When the account no longer takes part in the task's discussion.
     * @throws ValidationException When the task is Done.
     * @throws CommentReplyOutsideTaskException When the parent comment is not a comment of this task.
     */
    public function store(Task $task, Account $author, array $data): TaskComment
    {
        return DB::transaction(function () use ($task, $author, $data) {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if (! $locked->commentableBy($author)) {
                throw new AuthorizationException;
            }

            if ($locked->status === TaskStatus::Done) {
                throw ValidationException::withMessages([
                    'content' => 'Tugas yang sudah selesai tidak dapat dikomentari.',
                ]);
            }

            $parentId = isset($data['parent_comment_id']) ? (int) $data['parent_comment_id'] : null;

            if ($parentId !== null && ! $locked->comments()->whereKey($parentId)->exists()) {
                throw new CommentReplyOutsideTaskException;
            }

            $duplicate = $locked->comments()
                ->where('author_id', $author->id)
                ->where('parent_comment_id', $parentId)
                ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
                ->get()
                ->last(fn (TaskComment $comment) => $comment->content === $data['content']);

            if ($duplicate) {
                return $duplicate->load(self::RELATIONS);
            }

            $comment = $locked->comments()->create([
                'author_id' => $author->id,
                'parent_comment_id' => $parentId,
                'content' => $data['content'],
            ]);

            $locked->activities()->create([
                'account_id' => $author->id,
                'action' => TaskActivity::COMMENT_ADDED,
                'changes' => ['comment' => [
                    'old' => null,
                    'new' => ['id' => $comment->id, 'parent_comment_id' => $parentId],
                ]],
            ]);

            return $comment->load(self::RELATIONS);
        });
    }
}
