<?php

use App\Http\Controllers\Api\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\Api\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Api\Admin\InternshipAssignmentController as AdminInternshipAssignmentController;
use App\Http\Controllers\Api\Admin\InternshipController;
use App\Http\Controllers\Api\Admin\InternshipDocumentController as AdminInternshipDocumentController;
use App\Http\Controllers\Api\Admin\InterviewController as AdminInterviewController;
use App\Http\Controllers\Api\Admin\JobPostingController;
use App\Http\Controllers\Api\Admin\PlacementDriveController as AdminPlacementDriveController;
use App\Http\Controllers\Api\Admin\WeeklyProgressReportController as AdminWeeklyProgressReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Dashboard\AdminDashboardController;
use App\Http\Controllers\Api\Dashboard\FacultyDashboardController;
use App\Http\Controllers\Api\Dashboard\StudentDashboardController;
use App\Http\Controllers\Api\Dashboard\SuperAdminDashboardController;
use App\Http\Controllers\Api\Faculty\AttendanceController as FacultyAttendanceController;
use App\Http\Controllers\Api\Faculty\InternshipAssignmentController as FacultyInternshipAssignmentController;
use App\Http\Controllers\Api\Faculty\InternshipDocumentController as FacultyInternshipDocumentController;
use App\Http\Controllers\Api\Faculty\StudentController as FacultyStudentController;
use App\Http\Controllers\Api\Faculty\WeeklyProgressReportController as FacultyWeeklyProgressReportController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\Student\ApplicationController as StudentApplicationController;
use App\Http\Controllers\Api\Student\AttendanceController as StudentAttendanceController;
use App\Http\Controllers\Api\Student\CertificationController;
use App\Http\Controllers\Api\Student\ExperienceController;
use App\Http\Controllers\Api\Student\InternshipDocumentController as StudentInternshipDocumentController;
use App\Http\Controllers\Api\Student\InterviewController as StudentInterviewController;
use App\Http\Controllers\Api\Student\OpportunityController;
use App\Http\Controllers\Api\Student\PlacementDriveController as StudentPlacementDriveController;
use App\Http\Controllers\Api\Student\ProjectController;
use App\Http\Controllers\Api\Student\ResumeController;
use App\Http\Controllers\Api\Student\SkillController;
use App\Http\Controllers\Api\Student\WeeklyProgressReportController as StudentWeeklyProgressReportController;
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
    // The .../apply routes (Application Lifecycle increment) live in this
    // same group since they need the identical job/internship route-model
    // resolution the show routes already use - see
    // OpportunityController::applyToJob/applyToInternship.
    Route::prefix('student/opportunities')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [OpportunityController::class, 'index']);
        Route::get('/jobs/{jobPosting}', [OpportunityController::class, 'showJob']);
        Route::get('/internships/{internship}', [OpportunityController::class, 'showInternship']);
        Route::post('/jobs/{jobPosting}/apply', [OpportunityController::class, 'applyToJob']);
        Route::post('/internships/{internship}/apply', [OpportunityController::class, 'applyToInternship']);
    });

    // Application Lifecycle - a student's own applications (list/detail/
    // withdraw). Object-level ownership is enforced by ApplicationPolicy
    // inside Api\Student\ApplicationController, on top of this role gate.
    Route::prefix('student/applications')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [StudentApplicationController::class, 'index']);
        Route::get('/{application}', [StudentApplicationController::class, 'show']);
        Route::post('/{application}/withdraw', [StudentApplicationController::class, 'withdraw']);
    });

    // Application Lifecycle - placement-admin management of applications.
    // Object-level authorization is enforced by ApplicationPolicy inside
    // Api\Admin\ApplicationController, on top of this role gate.
    Route::prefix('admin/applications')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminApplicationController::class, 'index']);
        Route::get('/{application}', [AdminApplicationController::class, 'show']);
        Route::patch('/{application}/status', [AdminApplicationController::class, 'updateStatus']);
        Route::post('/{application}/interviews', [AdminInterviewController::class, 'store']);
    });

    // Interview Management - a student's own interviews (list/detail),
    // tied to their own applications. Object-level ownership is
    // enforced by InterviewPolicy inside Api\Student\InterviewController,
    // on top of this role gate.
    Route::prefix('student/interviews')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [StudentInterviewController::class, 'index']);
        Route::get('/{interview}', [StudentInterviewController::class, 'show']);
    });

    // Interview Management - placement-admin management of interviews.
    // Object-level authorization is enforced by InterviewPolicy inside
    // Api\Admin\InterviewController, on top of this role gate. Creation
    // lives under admin/applications/{application}/interviews above,
    // since an interview always belongs to a specific application.
    Route::prefix('admin/interviews')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminInterviewController::class, 'index']);
        Route::get('/{interview}', [AdminInterviewController::class, 'show']);
        Route::patch('/{interview}', [AdminInterviewController::class, 'update']);
        Route::post('/{interview}/reschedule', [AdminInterviewController::class, 'reschedule']);
        Route::post('/{interview}/complete', [AdminInterviewController::class, 'complete']);
        Route::post('/{interview}/cancel', [AdminInterviewController::class, 'cancel']);
    });

    // Placement Drive Management - a college recruitment event/
    // container grouping existing JobPosting/Internship opportunities
    // (Company -> Placement Drive -> Job/Internship -> Applications ->
    // Interviews -> Selections). Object-level authorization is enforced
    // by PlacementDrivePolicy inside Api\Student\PlacementDriveController/
    // Api\Admin\PlacementDriveController, on top of these role gates.
    Route::prefix('student/placement-drives')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [StudentPlacementDriveController::class, 'index']);
        Route::get('/{placementDrive}', [StudentPlacementDriveController::class, 'show']);
    });

    Route::prefix('admin/placement-drives')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminPlacementDriveController::class, 'index']);
        Route::post('/', [AdminPlacementDriveController::class, 'store']);
        Route::get('/{placementDrive}', [AdminPlacementDriveController::class, 'show']);
        Route::patch('/{placementDrive}', [AdminPlacementDriveController::class, 'update']);

        Route::post('/{placementDrive}/publish', [AdminPlacementDriveController::class, 'publish']);
        Route::post('/{placementDrive}/close', [AdminPlacementDriveController::class, 'close']);
        Route::post('/{placementDrive}/cancel', [AdminPlacementDriveController::class, 'cancel']);

        Route::post('/{placementDrive}/opportunities', [AdminPlacementDriveController::class, 'attachOpportunity']);
        Route::delete('/{placementDrive}/opportunities/{opportunity}', [AdminPlacementDriveController::class, 'detachOpportunity']);
    });

    // Internship Assignment + Faculty Coordination - the faculty-
    // coordination relationship for a SELECTED internship application
    // (Student -> Internship -> Application -> SELECTED -> Internship
    // Assignment -> Faculty Coordinator). Object-level authorization is
    // enforced by InternshipAssignmentPolicy inside
    // Api\Admin\InternshipAssignmentController /
    // Api\Faculty\InternshipAssignmentController, on top of these role
    // gates - faculty access is strictly assignment-based: a faculty
    // member can only ever see assignments/students explicitly
    // assigned to THEM (see the policy's docblock), never all students
    // or all internships, and cannot create, reassign, activate,
    // complete or cancel anything - those remain admin-only.
    Route::prefix('admin/internship-assignments')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminInternshipAssignmentController::class, 'index']);
        Route::post('/', [AdminInternshipAssignmentController::class, 'store']);
        Route::get('/{assignment}', [AdminInternshipAssignmentController::class, 'show']);

        Route::patch('/{assignment}/faculty', [AdminInternshipAssignmentController::class, 'reassignFaculty']);

        Route::post('/{assignment}/activate', [AdminInternshipAssignmentController::class, 'activate']);
        Route::post('/{assignment}/complete', [AdminInternshipAssignmentController::class, 'complete']);
        Route::post('/{assignment}/cancel', [AdminInternshipAssignmentController::class, 'cancel']);
    });

    Route::prefix('faculty/internship-assignments')->middleware('role:FACULTY')->group(function () {
        Route::get('/', [FacultyInternshipAssignmentController::class, 'index']);
        Route::get('/{assignment}', [FacultyInternshipAssignmentController::class, 'show']);
    });

    Route::get('/faculty/students', [FacultyStudentController::class, 'index'])
        ->middleware('role:FACULTY');

    // Attendance Management - scoped exclusively through
    // InternshipAssignment -> Faculty/Student (Attendance has no
    // student_id/faculty_id column of its own - see
    // App\Models\Attendance's docblock). Object-level authorization is
    // enforced by AttendancePolicy (and, for the two assignment-scoped
    // GET routes below, InternshipAssignmentPolicy::view()) inside each
    // controller, on top of these role gates. There is no admin create
    // route - marking new attendance is exclusively a faculty action;
    // admin may only view and correct existing records.
    Route::prefix('student/attendance')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [StudentAttendanceController::class, 'index']);
        Route::get('/{attendance}', [StudentAttendanceController::class, 'show']);
    });

    Route::get('/student/internship-assignments/{assignment}/attendance', [StudentAttendanceController::class, 'forAssignment'])
        ->middleware('role:STUDENT');

    Route::prefix('faculty/attendance')->middleware('role:FACULTY')->group(function () {
        Route::get('/', [FacultyAttendanceController::class, 'index']);
        Route::patch('/{attendance}', [FacultyAttendanceController::class, 'update']);
    });

    Route::middleware('role:FACULTY')->group(function () {
        Route::get('/faculty/internship-assignments/{assignment}/attendance', [FacultyAttendanceController::class, 'forAssignment']);
        Route::post('/faculty/internship-assignments/{assignment}/attendance', [FacultyAttendanceController::class, 'store']);
    });

    Route::prefix('admin/attendance')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminAttendanceController::class, 'index']);
        Route::get('/{attendance}', [AdminAttendanceController::class, 'show']);
        Route::patch('/{attendance}', [AdminAttendanceController::class, 'update']);
    });

    // Weekly Progress Reports - scoped exclusively through
    // InternshipAssignment -> Faculty/Student, mirroring Attendance's
    // own scoping pattern (see App\Models\WeeklyProgressReport's
    // docblock for why this model has no student_id/faculty_id column
    // of its own). Object-level authorization is enforced by
    // WeeklyProgressReportPolicy (and, for the assignment-scoped GET
    // routes below, InternshipAssignmentPolicy::view()) inside each
    // controller, on top of these role gates. There is no admin
    // create/update/submit/review route - admin gets read-only
    // visibility only (see Api\Admin\WeeklyProgressReportController's
    // docblock).
    Route::prefix('student/weekly-reports')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [StudentWeeklyProgressReportController::class, 'index']);
        Route::get('/{report}', [StudentWeeklyProgressReportController::class, 'show']);
        Route::patch('/{report}', [StudentWeeklyProgressReportController::class, 'update']);
        Route::post('/{report}/submit', [StudentWeeklyProgressReportController::class, 'submit']);
    });

    Route::middleware('role:STUDENT')->group(function () {
        Route::get('/student/internship-assignments/{assignment}/weekly-reports', [StudentWeeklyProgressReportController::class, 'forAssignment']);
        Route::post('/student/internship-assignments/{assignment}/weekly-reports', [StudentWeeklyProgressReportController::class, 'store']);
    });

    Route::prefix('faculty/weekly-reports')->middleware('role:FACULTY')->group(function () {
        Route::get('/', [FacultyWeeklyProgressReportController::class, 'index']);
        Route::get('/{report}', [FacultyWeeklyProgressReportController::class, 'show']);
        Route::post('/{report}/review', [FacultyWeeklyProgressReportController::class, 'review']);
    });

    Route::get('/faculty/internship-assignments/{assignment}/weekly-reports', [FacultyWeeklyProgressReportController::class, 'forAssignment'])
        ->middleware('role:FACULTY');

    Route::prefix('admin/weekly-reports')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminWeeklyProgressReportController::class, 'index']);
        Route::get('/{report}', [AdminWeeklyProgressReportController::class, 'show']);
    });

    // Internship Document Management - scoped exclusively through
    // InternshipAssignment -> Faculty/Student, mirroring Attendance/
    // Weekly Progress Reports' own scoping pattern (see
    // App\Models\InternshipDocument's docblock for why this model has
    // no student_id/faculty_id column of its own). Object-level
    // authorization is enforced by InternshipDocumentPolicy inside each
    // controller, on top of these role gates. There is no admin
    // create/resubmit/review route - admin gets read-only visibility
    // only (see Api\Admin\InternshipDocumentController's docblock).
    // There is deliberately no {assignment}-scoped route for store() -
    // unlike Weekly Progress Reports, the module spec's own route list
    // has upload posting straight to `/student/internship-documents`
    // with `internship_assignment_id` as a validated body field (see
    // StoreInternshipDocumentRequest's docblock).
    Route::prefix('student/internship-documents')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [StudentInternshipDocumentController::class, 'index']);
        Route::get('/{document}', [StudentInternshipDocumentController::class, 'show']);
        Route::post('/', [StudentInternshipDocumentController::class, 'store']);
        Route::post('/{document}/resubmit', [StudentInternshipDocumentController::class, 'resubmit']);
        Route::get('/{document}/download', [StudentInternshipDocumentController::class, 'download']);
    });

    Route::prefix('faculty/internship-documents')->middleware('role:FACULTY')->group(function () {
        Route::get('/', [FacultyInternshipDocumentController::class, 'index']);
        Route::get('/{document}', [FacultyInternshipDocumentController::class, 'show']);
        Route::get('/{document}/download', [FacultyInternshipDocumentController::class, 'download']);
        Route::patch('/{document}/status', [FacultyInternshipDocumentController::class, 'updateStatus']);
    });

    Route::prefix('admin/internship-documents')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminInternshipDocumentController::class, 'index']);
        Route::get('/{document}', [AdminInternshipDocumentController::class, 'show']);
        Route::get('/{document}/download', [AdminInternshipDocumentController::class, 'download']);
    });

    Route::get('/super-admin/dashboard', [SuperAdminDashboardController::class, 'index'])
        ->middleware('role:SUPER_ADMIN');
});
