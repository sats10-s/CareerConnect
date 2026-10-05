<?php

namespace App\Jobs;

use App\Models\ExcelImportBatch;
use App\Models\User;
use App\Services\StudentImport\StudentImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a confirmed Student Data import. Queued because creating
 * thousands of accounts means hashing thousands of first passwords; the
 * Import screen polls the batch's progress_done/progress_total meanwhile.
 * With QUEUE_CONNECTION=sync it simply runs inside the confirm request.
 */
class ConfirmStudentImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public readonly int $batchId, public readonly int $actorId) {}

    public function handle(StudentImportService $service): void
    {
        $batch = ExcelImportBatch::find($this->batchId);
        $actor = User::find($this->actorId);

        if ($batch && $actor) {
            $service->process($batch, $actor);
        }
    }
}
