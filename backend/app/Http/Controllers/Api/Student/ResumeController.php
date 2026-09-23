<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Api\Student\Concerns\ReturnsFreshProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\UploadResumeRequest;
use App\Http\Resources\StudentProfileResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Resume upload/download/delete. Security-critical - see the Student
 * Profile module spec, section 4:
 *
 *  - Stored on the `local` filesystem disk (storage/app/private/...,
 *    see config/filesystems.php), which Laravel does not expose
 *    publicly and has no symlink into public/ (unlike the `public`
 *    disk) - never a public URL.
 *  - The on-disk filename is a random 40-character string with an
 *    extension derived from the file's own detected content type
 *    (Symfony's UploadedFile::extension(), not the client-supplied
 *    original filename) - nothing about the stored path is derived
 *    from client input. The original filename is kept only as a
 *    display value (`resume_original_name`), never used to build a
 *    filesystem path.
 *  - Download/delete only ever act on the AUTHENTICATED student's own
 *    resume_path column - there is no {id}/filename route parameter
 *    for a client to manipulate (see routes/api.php), so there is no
 *    IDOR surface here by construction, not just by convention.
 *  - Replacing a resume stores + confirms the new file FIRST, updates
 *    the database row inside a transaction, and only deletes the old
 *    file after that transaction commits. If storing or the DB update
 *    fails partway, the previous resume is left untouched and any
 *    newly-stored file is cleaned up rather than orphaned.
 */
class ResumeController extends Controller
{
    use ReturnsFreshProfile;

    private const DISK = 'local';

    public function store(UploadResumeRequest $request): StudentProfileResource
    {
        $student = $request->user()->student;
        $file = $request->file('resume');

        $previousPath = $student->resume_path;

        $randomName = Str::random(40).'.'.$file->extension();
        $storedPath = $file->storeAs('resumes/'.$student->id, $randomName, self::DISK);

        if ($storedPath === false) {
            abort(500, 'Could not store the resume. Please try again.');
        }

        try {
            DB::transaction(function () use ($student, $file, $storedPath) {
                $student->update([
                    'resume_path' => $storedPath,
                    'resume_original_name' => $file->getClientOriginalName(),
                    'resume_size' => $file->getSize(),
                    'resume_uploaded_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // The DB write failed - remove the file we just stored so it
            // isn't left behind as an orphan, then re-throw. The
            // previous resume (if any) is untouched.
            Storage::disk(self::DISK)->delete($storedPath);
            throw $e;
        }

        // Only now - after the new file is confirmed stored AND the DB
        // row successfully points at it - remove the previous one.
        if ($previousPath && $previousPath !== $storedPath) {
            Storage::disk(self::DISK)->delete($previousPath);
        }

        return $this->freshProfile($request);
    }

    public function show(Request $request): StreamedResponse
    {
        $student = $request->user()->student;

        if (! $student->resume_path || ! Storage::disk(self::DISK)->exists($student->resume_path)) {
            abort(404, 'No resume on file.');
        }

        return Storage::disk(self::DISK)->download(
            $student->resume_path,
            $student->resume_original_name ?? 'resume'
        );
    }

    public function destroy(Request $request): StudentProfileResource
    {
        $student = $request->user()->student;

        if ($student->resume_path) {
            $path = $student->resume_path;

            DB::transaction(function () use ($student) {
                $student->update([
                    'resume_path' => null,
                    'resume_original_name' => null,
                    'resume_size' => null,
                    'resume_uploaded_at' => null,
                ]);
            });

            Storage::disk(self::DISK)->delete($path);
        }

        return $this->freshProfile($request);
    }
}
