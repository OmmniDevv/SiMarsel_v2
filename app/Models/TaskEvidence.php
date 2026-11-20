<?php

namespace App\Models;

use App\Services\TaskEvidenceService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['task_id', 'submitter_id', 'type', 'file_path', 'file_name', 'mime_type', 'file_size', 'url', 'note', 'deleted_by'])]
class TaskEvidence extends Model
{
    use SoftDeletes;

    // "evidence" is uncountable to the pluralizer, which would otherwise look for task_evidence.
    protected $table = 'task_evidences';

    public const FILE = 'file';

    public const LINK = 'link';

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'submitter_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deleted_by');
    }

    /**
     * Whether the account may delete this evidence right now: only the PIC who uploaded it, while still an active PIC
     * with task.update, and only while the task still accepts evidence (To Do, On Process), so evidence that is being
     * or has been reviewed stays. Evidence without a known uploader cannot be deleted.
     */
    public function deletableBy(Account $account): bool
    {
        return $this->submitter_id !== null
            && $this->submitter_id === $account->id
            && $account->hasPermission(Permission::TASK_UPDATE)
            && in_array($this->task->status, TaskEvidenceService::SUBMITTABLE_STATUSES, true)
            && $this->task->assignedTo($account);
    }
}
