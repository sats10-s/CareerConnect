<?php

use App\Http\Controllers\Api\Admin\InternshipController;
use App\Http\Controllers\Api\Admin\JobPostingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Dashboard\AdminDashboardController;
use App\Http\Controllers\Api\Dashboard\FacultyDashboardController;
use App\Http\Controllers\Api\Dashboard\StudentDashboardController;
use App\Http\Controllers\Api\Dashboard\SuperAdminDashboardController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\Student\CertificationController;
use App\Http\Controllers\Api\Student\ExperienceController;
use App\Http\Controllers\Api\Student\OpportunityController;
use App\Http\Controllers\Api\Student\ProjectController;
use App\Http\Controllers\Api\Student\ResumeController;
use App\Http\Controllers\Api\Student\SkillController;
use App\Http\Controllers\Api\StudentProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Every route below is authenticated via Sanctum + gated by the `role`
| middleware where it matters. This is deliberate: the React app also
| hides nav it shouldn't show, but the *enforcement* happens here, not
| in the browser (see App\Http\Middleware\EnsureUserHasRole).
|
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::get('/meta/departments', [MetaController::class, 'departments']);
Route::get('/meta/batches', [MetaController::class, 'batches']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/student/dashboard', [StudentDashboardController::class, 'index'])
        ->middleware('role:STUDENT');

    // The Student Profile module. Every route here is additionally
    // gated by role:STUDENT; ownership of the specific skill/project/
    // certification/experience/resume being touched is enforced inside
    // each controller (via a Policy or, for the profile/resume routes,
    // by construction - see ReturnsFreshProfile and ResumeController).
    Route::prefix('student/profile')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [StudentProfileController::class, 'show']);
        Route::match(['put', 'patch'], '/', [StudentProfileController::class, 'update']);

        Route::post('/skills', [SkillController::class, 'store']);
        Route::delete('/skills/{skill}', [SkillController::class, 'destroy']);

        Route::post('/projects', [ProjectController::class, 'store']);
        Route::match(['put', 'patch'], '/projects/{project}', [ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

        Route::post('/certifications', [CertificationController::class, 'store']);
        Route::match(['put', 'patch'], '/certifications/{certification}', [CertificationController::class, 'update']);
        Route::delete('/certifications/{certification}', [CertificationController::class, 'destroy']);

        Route::post('/experiences', [ExperienceController::class, 'store']);
        Route::match(['put', 'patch'], '/experiences/{experience}', [ExperienceController::class, 'update']);
        Route::delete('/experiences/{experience}', [ExperienceController::class, 'destroy']);

        Route::post('/resume', [ResumeController::class, 'store']);
        Route::get('/resume', [ResumeController::class, 'show']);
        Route::delete('/resume', [ResumeController::class, 'destroy']);
    });

    Route::get('/faculty/dashboard', [FacultyDashboardController::class, 'index'])
        ->middleware('role:FACULTY');

    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])
        ->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN');

    // Jobs & Internships Backend Foundation - placement-admin management.
    // Object-level authorization (beyond this role gate) is enforced by
    // JobPostingPolicy/InternshipPolicy inside each controller action.
    Route::prefix('admin')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/jobs', [JobPostingController::class, 'index']);
        Route::post('/jobs', [JobPostingController::class, 'store']);
        Route::get('/jobs/{jobPosting}', [JobPostingController::class, 'show']);
        Route::match(['put', 'patch'], '/jobs/{jobPosting}', [JobPostingController::class, 'update']);

        Route::get('/internships', [InternshipController::class, 'index']);
        Route::post('/internships', [InternshipController::class, 'store']);
        Route::get('/internships/{internship}', [InternshipController::class, 'show']);
        Route::match(['put', 'patch'], '/internships/{internship}', [InternshipController::class, 'update']);
    });

    // Jobs & Internships Backend Foundation - the student-facing
    // published-opportunities listing (see Api\Student\OpportunityController
    // for the cross-table union/pagination behind /student/opportunities).
    Route::prefix('student/opportunities')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [OpportunityController::class, 'index']);
        Route::get('/jobs/{jobPosting}', [OpportunityController::class, 'showJob']);
        Route::get('/internships/{internship}', [OpportunityController::class, 'showInternship']);
    });

    Route::get('/super-admin/dashboard', [SuperAdminDashboardController::class, 'index'])
        ->middleware('role:SUPER_ADMIN');
});
