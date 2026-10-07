<?php

use App\Http\Controllers\Api\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Api\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\Api\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Api\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Api\Admin\ExcelExportController as AdminExcelExportController;
use App\Http\Controllers\Api\Admin\ExcelImportController as AdminExcelImportController;
use App\Http\Controllers\Api\Admin\ExcelTemplateController as AdminExcelTemplateController;
use App\Http\Controllers\Api\Admin\InternshipAssignmentController as AdminInternshipAssignmentController;
use App\Http\Controllers\Api\Admin\InternshipCompletionController as AdminInternshipCompletionController;
use App\Http\Controllers\Api\Admin\InternshipController;
use App\Http\Controllers\Api\Admin\InternshipDocumentController as AdminInternshipDocumentController;
use App\Http\Controllers\Api\Admin\InterviewController as AdminInterviewController;
use App\Http\Controllers\Api\Admin\JobPostingController;
use App\Http\Controllers\Api\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Api\Admin\PlacementDriveController as AdminPlacementDriveController;
use App\Http\Controllers\Api\Admin\PpoRecordController as AdminPpoRecordController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\StudentController as AdminStudentController;
use App\Http\Controllers\Api\Admin\WeeklyProgressReportController as AdminWeeklyProgressReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Company\ApplicantController as CompanyApplicantController;
use App\Http\Controllers\Api\Company\DashboardController as CompanyDashboardController;
use App\Http\Controllers\Api\Company\InterviewController as CompanyInterviewController;
use App\Http\Controllers\Api\Company\MemberController as CompanyMemberController;
use App\Http\Controllers\Api\Company\NotificationController as CompanyNotificationController;
use App\Http\Controllers\Api\Company\PostingController as CompanyPostingController;
use App\Http\Controllers\Api\Company\ProfileController as CompanyProfileController;
use App\Http\Controllers\Api\Company\RegistrationController as CompanyRegistrationController;
use App\Http\Controllers\Api\Dashboard\AdminDashboardController;
use App\Http\Controllers\Api\Dashboard\FacultyDashboardController;
use App\Http\Controllers\Api\Dashboard\StudentDashboardController;
use App\Http\Controllers\Api\Dashboard\SuperAdminDashboardController;
use App\Http\Controllers\Api\Faculty\AnnouncementController as FacultyAnnouncementController;
use App\Http\Controllers\Api\Faculty\AttendanceController as FacultyAttendanceController;
use App\Http\Controllers\Api\Faculty\InternshipAssignmentController as FacultyInternshipAssignmentController;
use App\Http\Controllers\Api\Faculty\InternshipCompletionController as FacultyInternshipCompletionController;
use App\Http\Controllers\Api\Faculty\InternshipDocumentController as FacultyInternshipDocumentController;
use App\Http\Controllers\Api\Faculty\NotificationController as FacultyNotificationController;
use App\Http\Controllers\Api\Faculty\PpoRecordController as FacultyPpoRecordController;
use App\Http\Controllers\Api\Faculty\ReportController as FacultyReportController;
use App\Http\Controllers\Api\Faculty\StudentController as FacultyStudentController;
use App\Http\Controllers\Api\Faculty\WeeklyProgressReportController as FacultyWeeklyProgressReportController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\Student\AnnouncementController as StudentAnnouncementController;
use App\Http\Controllers\Api\Student\ApplicationController as StudentApplicationController;
use App\Http\Controllers\Api\Student\AttendanceController as StudentAttendanceController;
use App\Http\Controllers\Api\Student\CertificationController;
use App\Http\Controllers\Api\Student\CorrectionRequestController as StudentCorrectionRequestController;
use App\Http\Controllers\Api\Student\ExperienceController;
use App\Http\Controllers\Api\Student\InternshipAssignmentController as StudentInternshipAssignmentController;
use App\Http\Controllers\Api\Student\InternshipCompletionController as StudentInternshipCompletionController;
use App\Http\Controllers\Api\Student\InternshipDocumentController as StudentInternshipDocumentController;
use App\Http\Controllers\Api\Student\InterviewController as StudentInterviewController;
use App\Http\Controllers\Api\Student\NotificationController as StudentNotificationController;
use App\Http\Controllers\Api\Student\OpportunityController;
use App\Http\Controllers\Api\Student\PlacementDriveController as StudentPlacementDriveController;
use App\Http\Controllers\Api\Student\PpoRecordController as StudentPpoRecordController;
use App\Http\Controllers\Api\Student\ProjectController;
use App\Http\Controllers\Api\Student\ReportController as StudentReportController;
use App\Http\Controllers\Api\Student\ResumeController;
use App\Http\Controllers\Api\Student\SkillController;
use App\Http\Controllers\Api\Student\WeeklyProgressReportController as StudentWeeklyProgressReportController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\SuperAdmin\AuditLogController as SuperAdminAuditLogController;
use App\Http\Controllers\Api\SuperAdmin\BatchController as SuperAdminBatchController;
use App\Http\Controllers\Api\SuperAdmin\DepartmentController as SuperAdminDepartmentController;
use App\Http\Controllers\Api\SuperAdmin\CompanyApprovalController as SuperAdminCompanyApprovalController;
use App\Http\Controllers\Api\SuperAdmin\RoleController as SuperAdminRoleController;
use App\Http\Controllers\Api\SuperAdmin\SecurityController as SuperAdminSecurityController;
use App\Http\Controllers\Api\SuperAdmin\SettingController as SuperAdminSettingController;
use App\Http\Controllers\Api\SuperAdmin\StudentAccountController as SuperAdminStudentAccountController;
use App\Http\Controllers\Api\SuperAdmin\StudentDataController as SuperAdminStudentDataController;
use App\Http\Controllers\Api\SuperAdmin\DepartmentAliasController as SuperAdminDepartmentAliasController;
use App\Http\Controllers\Api\SuperAdmin\UserController as SuperAdminUserController;
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
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // Reachable while a first-time password is still in use - everything
    // else answers 403 until the student sets their own (password.changed).
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/first-login', [AuthController::class, 'firstLogin']);
    });
});

Route::get('/meta/departments', [MetaController::class, 'departments']);
Route::get('/meta/batches', [MetaController::class, 'batches']);
Route::get('/meta/settings', [MetaController::class, 'settings']);

// Public company self-registration (rate limited per IP; closed when the
// allow_company_registration setting is off).
Route::post('/company/register', [CompanyRegistrationController::class, 'store'])
    ->middleware('throttle:company-registration');

Route::middleware(['auth:sanctum', 'password.changed'])->group(function () {
    // Company portal. /me, /profile and /notifications stay open while the company is
    // PENDING/REJECTED/SUSPENDED; everything else needs `company.approved`.
    // The company is always $request->user()->company, never a route/body id.
    Route::prefix('company')->middleware('role:COMPANY')->group(function () {
        Route::get('/me', [CompanyProfileController::class, 'show']);
        Route::patch('/profile', [CompanyProfileController::class, 'update']);
        Route::get('/notifications', [CompanyNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [CompanyNotificationController::class, 'unreadCount']);
        Route::patch('/notifications/{notification}/read', [CompanyNotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [CompanyNotificationController::class, 'markAllRead']);

        Route::middleware('company.approved')->group(function () {
            Route::get('/dashboard', [CompanyDashboardController::class, 'index']);

            Route::get('/postings', [CompanyPostingController::class, 'index']);
            Route::post('/postings', [CompanyPostingController::class, 'store']);
            Route::get('/postings/{type}/{id}', [CompanyPostingController::class, 'show'])->whereIn('type', ['job', 'internship'])->whereNumber('id');
            Route::patch('/postings/{type}/{id}', [CompanyPostingController::class, 'update'])->whereIn('type', ['job', 'internship'])->whereNumber('id');
            Route::post('/postings/{type}/{id}/submit', [CompanyPostingController::class, 'submit'])->whereIn('type', ['job', 'internship'])->whereNumber('id');
            Route::post('/postings/{type}/{id}/close', [CompanyPostingController::class, 'close'])->whereIn('type', ['job', 'internship'])->whereNumber('id');

            Route::get('/applications', [CompanyApplicantController::class, 'index']);
            Route::get('/applications/{application}', [CompanyApplicantController::class, 'show'])->whereNumber('application');
            Route::patch('/applications/{application}/status', [CompanyApplicantController::class, 'updateStatus'])->whereNumber('application');
            Route::get('/applications/{application}/resume', [CompanyApplicantController::class, 'resume'])->whereNumber('application');
            Route::post('/applications/{application}/interviews', [CompanyInterviewController::class, 'store']);

            Route::get('/interviews', [CompanyInterviewController::class, 'index']);
            Route::get('/interviews/{interview}', [CompanyInterviewController::class, 'show'])->whereNumber('interview');
            Route::post('/interviews/{interview}/reschedule', [CompanyInterviewController::class, 'reschedule']);
            Route::post('/interviews/{interview}/complete', [CompanyInterviewController::class, 'complete']);
            Route::post('/interviews/{interview}/cancel', [CompanyInterviewController::class, 'cancel']);

            Route::get('/members', [CompanyMemberController::class, 'index']);
            Route::post('/members', [CompanyMemberController::class, 'store']);
            Route::patch('/members/{member}/status', [CompanyMemberController::class, 'updateStatus'])->whereNumber('member');
        });
    });

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
        Route::get('/skills/suggestions', [SkillController::class, 'suggestions']);
        Route::post('/corrections', [StudentCorrectionRequestController::class, 'store']);
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
        Route::patch('/{application}/offer', [AdminApplicationController::class, 'updateOffer']);
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

    // Placement Admin > Students (read-only directory + resume download)
    // and Companies (list/add/edit, no delete - see the controllers'
    // docblocks). CompanyPolicy / StudentPolicy::viewAny|viewAsAdmin add
    // the object-level check on top of this role gate.
    Route::prefix('admin/students')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminStudentController::class, 'index']);
        Route::get('/{student}', [AdminStudentController::class, 'show']);
        Route::get('/{student}/resume', [AdminStudentController::class, 'resume']);
        Route::post('/{student}/corrections/{correction}/resolve', [AdminStudentController::class, 'resolveCorrection']);
        Route::post('/{student}/academics', [AdminStudentController::class, 'updateAcademics']);
    });

    Route::prefix('admin/companies')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminCompanyController::class, 'index']);
        Route::post('/', [AdminCompanyController::class, 'store']);
        Route::get('/{company}', [AdminCompanyController::class, 'show']);
        Route::patch('/{company}', [AdminCompanyController::class, 'update']);
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
    Route::get('/faculty/students/{student}', [FacultyStudentController::class, 'show'])
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

    // Internship Completion & PPO - scoped exclusively through
    // InternshipAssignment -> Faculty/Student, mirroring Attendance/
    // Weekly Progress Reports/Internship Documents' own scoping pattern
    // (see App\Models\InternshipCompletion/PpoRecord's docblocks for
    // why neither model has a student_id/faculty_id column of its
    // own). Object-level authorization is enforced by
    // InternshipCompletionPolicy/PpoRecordPolicy inside each
    // controller, on top of these role gates. There is no admin
    // create/update/finalize route for either resource - admin gets
    // read-only visibility only (see Api\Admin\InternshipCompletionController/
    // PpoRecordController's docblocks), matching the module spec's own
    // Admin API list (GET-only for both).
    //
    // The module spec's own route list gives no PPO write route at all,
    // and no dedicated completion-finalize route - both are reasoned
    // additions; see StorePpoRecordRequest's and
    // FinalizeInternshipCompletionRequest's docblocks for the full
    // routing rationale. store()/update() for both resources are
    // {assignment}-scoped (never a {completion}/{ppoRecord} segment),
    // exactly matching the module spec's own
    // POST/PATCH .../internship-assignments/{assignment}/completion
    // shape - mirrors the Attendance/Weekly Progress Reports precedent
    // of a plain `Route::middleware('role:FACULTY')->group(...)` block
    // with full explicit paths, rather than nesting under the
    // pre-existing `faculty/internship-assignments` prefix group above
    // (which only ever handles read access to the assignment itself).
    Route::middleware('role:FACULTY')->group(function () {
        Route::post('/faculty/internship-assignments/{assignment}/completion', [FacultyInternshipCompletionController::class, 'store']);
        Route::patch('/faculty/internship-assignments/{assignment}/completion', [FacultyInternshipCompletionController::class, 'update']);
        Route::post('/faculty/internship-assignments/{assignment}/ppo', [FacultyPpoRecordController::class, 'store']);
        Route::patch('/faculty/internship-assignments/{assignment}/ppo', [FacultyPpoRecordController::class, 'update']);
    });

    Route::prefix('faculty/internship-completions')->middleware('role:FACULTY')->group(function () {
        Route::get('/', [FacultyInternshipCompletionController::class, 'index']);
        Route::get('/{completion}', [FacultyInternshipCompletionController::class, 'show']);
        Route::post('/{completion}/finalize', [FacultyInternshipCompletionController::class, 'finalize']);
    });

    Route::prefix('faculty/ppo-records')->middleware('role:FACULTY')->group(function () {
        Route::get('/', [FacultyPpoRecordController::class, 'index']);
        Route::get('/{ppoRecord}', [FacultyPpoRecordController::class, 'show']);
    });

    Route::prefix('admin/internship-completions')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminInternshipCompletionController::class, 'index']);
        Route::get('/{completion}', [AdminInternshipCompletionController::class, 'show']);
    });

    Route::prefix('admin/ppo-records')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminPpoRecordController::class, 'index']);
        Route::get('/{ppoRecord}', [AdminPpoRecordController::class, 'show']);
    });

    // A student's own internship assignment - added during the
    // Student role frontend build-out. Mirrors the internship-completion/
    // ppo routes just below (singular GET, no {assignment} segment,
    // data: null when none exists) - see
    // Api\Student\InternshipAssignmentController's docblock for why this
    // was missing: every assignment-scoped sub-resource route
    // (attendance/weekly-reports/documents) needs an assignment id the
    // student previously had no API-visible way to discover.
    Route::get('/student/internship-assignment', [StudentInternshipAssignmentController::class, 'show'])
        ->middleware('role:STUDENT');

    Route::get('/student/internship-completion', [StudentInternshipCompletionController::class, 'show'])
        ->middleware('role:STUDENT');

    Route::get('/student/ppo', [StudentPpoRecordController::class, 'show'])
        ->middleware('role:STUDENT');

    // Super Admin console (role:SUPER_ADMIN only): accounts, roles,
    // departments, batches, audit log, security overview and settings.
    // Every write is recorded through App\Services\AuditLogger.
    Route::prefix('super-admin')->middleware('role:SUPER_ADMIN')->group(function () {
        Route::get('/users', [SuperAdminUserController::class, 'index']);
        Route::post('/users', [SuperAdminUserController::class, 'store']);
        Route::get('/users/{user}', [SuperAdminUserController::class, 'show']);
        Route::patch('/users/{user}', [SuperAdminUserController::class, 'update']);
        Route::patch('/users/{user}/status', [SuperAdminUserController::class, 'updateStatus']);
        Route::post('/users/{user}/password', [SuperAdminUserController::class, 'resetPassword']);
        Route::delete('/users/{user}/sessions', [SuperAdminUserController::class, 'revokeSessions']);

        Route::get('/roles', [SuperAdminRoleController::class, 'index']);

        Route::get('/departments', [SuperAdminDepartmentController::class, 'index']);
        Route::post('/departments', [SuperAdminDepartmentController::class, 'store']);
        Route::patch('/departments/{department}', [SuperAdminDepartmentController::class, 'update']);
        Route::delete('/departments/{department}', [SuperAdminDepartmentController::class, 'destroy']);

        Route::get('/batches', [SuperAdminBatchController::class, 'index']);
        Route::post('/batches', [SuperAdminBatchController::class, 'store']);
        Route::patch('/batches/{batch}', [SuperAdminBatchController::class, 'update']);
        Route::delete('/batches/{batch}', [SuperAdminBatchController::class, 'destroy']);

        Route::get('/audit-logs', [SuperAdminAuditLogController::class, 'index']);
        Route::get('/security', [SuperAdminSecurityController::class, 'index']);
        Route::get('/settings', [SuperAdminSettingController::class, 'index']);
        Route::patch('/settings', [SuperAdminSettingController::class, 'update']);

        // Student Data: the college's student file and semester results.
        Route::get('/student-data/imports', [SuperAdminStudentDataController::class, 'index']);
        Route::get('/student-data/templates/placement-admins', [SuperAdminStudentDataController::class, 'placementAdminTemplate']);
        Route::post('/student-data/imports', [SuperAdminStudentDataController::class, 'store']);
        Route::get('/student-data/imports/{batch}', [SuperAdminStudentDataController::class, 'show']);
        Route::get('/student-data/imports/{batch}/rows', [SuperAdminStudentDataController::class, 'rows']);
        Route::get('/student-data/imports/{batch}/issues', [SuperAdminStudentDataController::class, 'issues']);
        Route::post('/student-data/imports/{batch}/recheck', [SuperAdminStudentDataController::class, 'recheck']);
        Route::post('/student-data/imports/{batch}/confirm', [SuperAdminStudentDataController::class, 'confirm']);
        Route::post('/student-data/imports/{batch}/cancel', [SuperAdminStudentDataController::class, 'cancel']);
        Route::post('/student-data/imports/{batch}/rollback', [SuperAdminStudentDataController::class, 'rollback']);
        Route::post('/student-data/imports/{batch}/one-time-passwords', [SuperAdminStudentDataController::class, 'oneTimePasswords']);
        Route::post('/students/{student}/reset-password', [SuperAdminStudentAccountController::class, 'resetPassword']);
        Route::get('/student-data/department-aliases', [SuperAdminDepartmentAliasController::class, 'index']);
        Route::post('/student-data/department-aliases', [SuperAdminDepartmentAliasController::class, 'store']);
        Route::delete('/student-data/department-aliases/{alias}', [SuperAdminDepartmentAliasController::class, 'destroy']);
        Route::get('/companies', [SuperAdminCompanyApprovalController::class, 'index']);
        Route::get('/companies/{company}', [SuperAdminCompanyApprovalController::class, 'show']);
        Route::post('/companies/{company}/approve', [SuperAdminCompanyApprovalController::class, 'approve']);
        Route::post('/companies/{company}/reject', [SuperAdminCompanyApprovalController::class, 'reject']);
        Route::post('/companies/{company}/suspend', [SuperAdminCompanyApprovalController::class, 'suspend']);
        Route::post('/companies/{company}/reactivate', [SuperAdminCompanyApprovalController::class, 'reactivate']);
    });

    Route::get('/super-admin/dashboard', [SuperAdminDashboardController::class, 'index'])
        ->middleware('role:SUPER_ADMIN');

    // Excel Sync Center (module spec sections 1-38) - bulk Excel
    // import/export/templates/history. Route-gated to admins only
    // (module spec section 33); object-level authorization is enforced
    // by ExcelImportBatchPolicy inside each controller action, same
    // pattern as every other admin-only module in this file. Matches
    // the module spec's own exact route list (section 32).
    Route::prefix('admin/excel')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::post('/import', [AdminExcelImportController::class, 'store']);
        Route::get('/imports', [AdminExcelImportController::class, 'index']);
        Route::get('/imports/{batch}', [AdminExcelImportController::class, 'show']);
        Route::get('/imports/{batch}/preview', [AdminExcelImportController::class, 'preview']);
        Route::post('/imports/{batch}/confirm', [AdminExcelImportController::class, 'confirm']);
        Route::post('/imports/{batch}/rollback', [AdminExcelImportController::class, 'rollback']);
        Route::get('/templates/{type}', [AdminExcelTemplateController::class, 'show']);
        Route::get('/exports/{type}', [AdminExcelExportController::class, 'show']);
    });

    // Notifications & Announcements - persistent in-app notifications
    // (App\Models\Notification, one row per recipient) and admin-
    // authored announcements (App\Models\Announcement). Object-level
    // authorization is enforced by NotificationPolicy/AnnouncementPolicy
    // inside each controller action, on top of these role gates -
    // NotificationPolicy in particular has NO admin bypass at all (see
    // its own docblock): every role, SUPER_ADMIN included, can only
    // ever read/mark its OWN notifications. Announcement management
    // (create/edit/publish/archive) is admin-only; student/faculty
    // announcement routes are read-only (list/show), matching the
    // module spec's "Students and Faculty ... cannot create/edit/
    // publish/archive".
    Route::prefix('student/notifications')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [StudentNotificationController::class, 'index']);
        Route::get('/unread-count', [StudentNotificationController::class, 'unreadCount']);
        Route::patch('/{notification}/read', [StudentNotificationController::class, 'markRead']);
        Route::post('/read-all', [StudentNotificationController::class, 'markAllRead']);
    });

    Route::prefix('faculty/notifications')->middleware('role:FACULTY')->group(function () {
        Route::get('/', [FacultyNotificationController::class, 'index']);
        Route::get('/unread-count', [FacultyNotificationController::class, 'unreadCount']);
        Route::patch('/{notification}/read', [FacultyNotificationController::class, 'markRead']);
        Route::post('/read-all', [FacultyNotificationController::class, 'markAllRead']);
    });

    Route::prefix('admin/notifications')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminNotificationController::class, 'index']);
        Route::get('/unread-count', [AdminNotificationController::class, 'unreadCount']);
        Route::patch('/{notification}/read', [AdminNotificationController::class, 'markRead']);
        Route::post('/read-all', [AdminNotificationController::class, 'markAllRead']);
    });

    Route::prefix('student/announcements')->middleware('role:STUDENT')->group(function () {
        Route::get('/', [StudentAnnouncementController::class, 'index']);
        Route::get('/{announcement}', [StudentAnnouncementController::class, 'show']);
    });

    Route::prefix('faculty/announcements')->middleware('role:FACULTY')->group(function () {
        Route::get('/', [FacultyAnnouncementController::class, 'index']);
        Route::get('/{announcement}', [FacultyAnnouncementController::class, 'show']);
    });

    Route::prefix('admin/announcements')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/', [AdminAnnouncementController::class, 'index']);
        Route::post('/', [AdminAnnouncementController::class, 'store']);
        Route::get('/{announcement}', [AdminAnnouncementController::class, 'show']);
        Route::match(['put', 'patch'], '/{announcement}', [AdminAnnouncementController::class, 'update']);
        Route::post('/{announcement}/publish', [AdminAnnouncementController::class, 'publish']);
        Route::post('/{announcement}/archive', [AdminAnnouncementController::class, 'archive']);
    });

    // Reports & Analytics - read-only aggregate/analytics endpoints,
    // entirely additive alongside the pre-existing Api\Dashboard\*
    // controllers above (/api/{role}/dashboard), which are deliberately
    // left untouched. Every report is computed by the
    // App\Services\Reporting\* services - the SAME calculation path the
    // Excel Sync Center's analytical exports now delegate to (see
    // App\Services\ExcelExportService), so an API report and its Excel
    // equivalent can never silently disagree.
    //
    // Student/faculty routes return only the acting user's own data
    // (see Api\Student\ReportController / Api\Faculty\ReportController -
    // no id parameter exists on either, so there is nothing to swap for
    // an IDOR attempt). The admin report endpoints are mounted TWICE -
    // once under `admin/reports` (role:PLACEMENT_ADMIN,SUPER_ADMIN,
    // matching every other admin/* group in this file) and once under
    // `super-admin/reports` (role:SUPER_ADMIN only) - both routing to
    // the SAME Api\Admin\ReportController class, since a super admin's
    // institution-wide report is identical in shape and scope to an
    // admin's; duplicating the controller class would only duplicate
    // the exact same code for no behavioural difference (see that
    // controller's own docblock).
    Route::get('/student/reports/summary', [StudentReportController::class, 'summary'])
        ->middleware('role:STUDENT');

    Route::get('/faculty/reports/summary', [FacultyReportController::class, 'summary'])
        ->middleware('role:FACULTY');

    Route::prefix('admin/reports')->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('/dashboard', [AdminReportController::class, 'dashboard']);
        Route::get('/placements', [AdminReportController::class, 'placements']);
        Route::get('/applications', [AdminReportController::class, 'applications']);
        Route::get('/internships', [AdminReportController::class, 'internships']);
        Route::get('/attendance', [AdminReportController::class, 'attendance']);
        Route::get('/faculty', [AdminReportController::class, 'faculty']);
        Route::get('/companies', [AdminReportController::class, 'companies']);
        Route::get('/trends', [AdminReportController::class, 'trends']);
    });

    Route::prefix('super-admin/reports')->middleware('role:SUPER_ADMIN')->group(function () {
        Route::get('/dashboard', [AdminReportController::class, 'dashboard']);
        Route::get('/placements', [AdminReportController::class, 'placements']);
        Route::get('/applications', [AdminReportController::class, 'applications']);
        Route::get('/internships', [AdminReportController::class, 'internships']);
        Route::get('/attendance', [AdminReportController::class, 'attendance']);
        Route::get('/faculty', [AdminReportController::class, 'faculty']);
        Route::get('/companies', [AdminReportController::class, 'companies']);
        Route::get('/trends', [AdminReportController::class, 'trends']);
    });
});
