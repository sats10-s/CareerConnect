# CareerConnect: Product Requirements Document

Status: reflects what is built on the `student-import` branch as of 2026-10-08.

## 1. Purpose

CareerConnect is the college's internship and placement portal. It replaces spreadsheets and email threads with one place where:

- the college loads its student records from Excel, so students never have to register;
- companies sign up, get approved, post jobs and internships, and run their hiring;
- students apply, track applications and interviews, and complete internships;
- faculty mentor interns (attendance, weekly reports, documents, evaluations);
- the placement office runs drives and produces accurate Excel reports by department, year and section.

## 2. Users and roles

| Role | Who | How they get an account | How they sign in |
|---|---|---|---|
| Super Admin | Placement office head / IT | Created at installation | College email + password |
| Placement Admin | Placement coordinators, one per department | Super Admin imports an Excel file (Name, Department, College Email, Password) or creates them under Users | College email + the password from the file, changed at first sign-in |
| Faculty | Internship mentors | Super Admin creates them under Users | College email + password |
| Student | B.Tech students | Super Admin imports the college's student file | JNTU No + mobile number as the first password, then their own password |
| Company recruiter | HR at a hiring company | Self sign-up at `/company/register`, approved by Super Admin; the owner can add teammates | Work email + password |

Student self-registration exists but is switched off by default (Settings > Student self-registration).

## 3. Sign-in and account security

- Students sign in with their JNTU No (case-insensitive); staff and recruiters with email.
- First password for an imported student is their registered mobile number. A student with no valid mobile gets a one-time password that the Super Admin sees once, at import time.
- At first sign-in a student must set a new password (8+ characters, not the mobile number or JNTU No) and add a personal email. Until then every other page is blocked.
- An unused first password stops working 30 days after it was issued.
- 5 wrong passwords lock an account for 15 minutes; one IP address is limited to 30 attempts per 10 minutes. Failures and lockouts go to the audit log.
- Forgot password sends a reset link to the student's personal email. The response is the same whether or not the account exists.
- Super Admin can reset a student back to "mobile number + forced change", which also signs them out everywhere.
- Passwords are always stored hashed (bcrypt). Plaintext from import files is never written to the database.

## 4. Modules

### 4.1 Student Data (Super Admin)

Loads and updates student records from the college's own Excel file.

- **Student import**: reads every sheet; tolerant header matching ("JNTU No", "Mobile No", "No. of Backlogs" and similar); cleans JNTU No, department names (via an alias table), section, year of joining (derived for laterals), CGPA, backlogs, 10th and Inter/Diploma scores, mobile and gender. B.Tech only; M.Tech rows are skipped.
- **Preview before saving**: tabs for New, Updated, Unchanged, Warnings and Errors, with per-sheet counts and old-to-new values. Large results changes are flagged. Errors can be downloaded as a file.
- **Confirm** runs in the background with a progress bar (chunks of 500 rows). Only one import runs at a time. A duplicate JNTU No fails only its own row.
- **Re-imports** update only changed fields; blank cells never erase data; passwords and anything the student entered are never overwritten.
- **Academic update** imports refresh CGPA and backlogs each semester, keep history, and notify the student.
- **Rollback** removes an import's students until one of them signs in or applies.
- **Unknown departments**: create the department or add an alias, then re-check the file.
- **Placement Admin import**: same flow for staff accounts, with a template download.

### 4.2 Student profile (Student)

- College record cards (read-only): name, JNTU No, department, year, section, batch, CGPA, backlogs, 10th and Inter scores, academic history.
- Student-editable: personal email, phone, address, links (LinkedIn, GitHub, portfolio), skills, projects, certifications, experience, resume (PDF/DOC/DOCX, up to 5 MB).
- Correction requests for wrong college data, resolved by a Placement Admin.
- Profile completion checklist.

### 4.3 Companies

- **Registration** (public): company details and owner login; creates a Pending company. Rate limited, and can be switched off in Settings.
- **Approval** (Super Admin): approve, reject, suspend, reactivate. Pending, rejected and suspended companies can only see their status page, profile and notifications.
- **Company portal** (recruiter): dashboard, postings (jobs and internships) submitted for Placement Admin review when that setting is on, applicants with resume download (own postings only), status changes, interview scheduling, team members.
- **Placement Admin** can also add and manage companies directly.

### 4.4 Opportunities and applications

- Jobs and internships with eligibility rules: departments, batches, years, minimum CGPA, maximum backlogs, required skills. Eligibility can be advisory or enforced.
- Students browse, see whether they are eligible, apply and withdraw.
- Application statuses: Applied, Under Review, Shortlisted, Assessment, Interview, Selected, Rejected, Withdrawn, with full status history.
- Offer package recorded on selection, used in package reports.

### 4.5 Placement drives and interviews

- Drives group several opportunities, with publish, close and cancel. Drives respect company approval status.
- Interviews: schedule, reschedule, complete, cancel, by Placement Admin or recruiter. Students see their schedule.

### 4.6 Internship lifecycle (Placement Admin, Faculty, Student)

- Assignment of a student's internship to a faculty mentor; activate, complete, cancel, reassign mentor.
- Attendance recorded by faculty, with an alert threshold (default 75%).
- Weekly progress reports submitted by students, reviewed by faculty (approve or request changes).
- Internship documents (offer letter, certificate and others) uploaded by students and verified by faculty.
- Completion evaluation and finalization by faculty; PPO (pre-placement offer) records.

### 4.7 Excel Sync and reports (Placement Admin)

- **Imports** of placement status, internship status, interview schedule, shortlist, assessment results, final selection and attendance, each with a template, preview, confirm and rollback.
- **Exports** (23 types), each with department, batch, year and section on every student row and filters for applied, shortlisted, interviewed and placed: students, applications, interviews, internships, PPOs, attendance, weekly reports, documents, drives, companies, company/department/batch/section-wise placement reports, eligible students for an opportunity, package report, batch comparison, unplaced students and application activity.
- **Reports page**: dashboards for placements, applications, internships, companies, attendance, faculty and trends. The Super Admin sees the same reports.

### 4.8 Notifications and announcements

- In-app notifications per role (application changes, review decisions, academic updates and so on), with unread counts.
- Announcements by Placement Admins with audience targeting, publish and archive.
- Email is used only for password reset links.

### 4.9 Administration (Super Admin)

- Users: create, edit, activate or deactivate, reset password, sign out of all sessions.
- Roles (read-only), departments, batches, department aliases.
- Security overview and audit log of sign-ins and changes.
- Settings: institution name, support email, academic year, student self-registration, company self-registration, company postings need review, attendance threshold.

## 5. Non-functional requirements

- **Authorization on the server**: every role route is gated in the API; the UI hiding a menu is never the only guard. Recruiters only ever see their own company's data.
- **Data safety**: imports are previewed before anything is written and can be rolled back; uploaded import files are deleted after confirm or cancel.
- **Performance**: imports and exports work in chunks of 500 rows; imports run on a background queue.
- **Mobile**: the student area has bottom navigation and works at phone width.
- **Stack**: React + TypeScript + Vite + Tailwind on the frontend; Laravel 13 (PHP 8.3+) with Sanctum on the backend; MySQL 8.

## 6. Out of scope for this release

- Faculty Excel import and export (Placement Admins only).
- Email notifications other than password reset; no SMS.
- Email verification for company sign-up.
- Alumni module and AI features.
