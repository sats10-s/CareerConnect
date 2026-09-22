# CareerConnect — Internship & Placement Management Portal

**Foundation build.** This sets up the project skeleton only: auth, roles,
core schema, layouts and dashboards. Feature work (Excel sync, attendance,
evaluations, placement drives, interviews, advanced reports, company
portal, alumni, AI features) is intentionally **not** implemented yet.

## Tech stack

| Layer     | Technology                                             |
|-----------|---------------------------------------------------------|
| Frontend  | React + TypeScript + Vite + Tailwind CSS + React Router + Lucide React |
| Backend   | PHP 8.3+ / Laravel 11 (REST API, Sanctum SPA auth)       |
| Database  | MySQL 8+                                                 |

## Project structure

```
CareerConnect/
├── backend/                  Laravel API
│   ├── app/
│   │   ├── Enums/            RoleName, ApplicationStatus, OpportunityType
│   │   ├── Http/
│   │   │   ├── Controllers/Api/         AuthController, MetaController
│   │   │   ├── Controllers/Api/Dashboard/  one controller per role dashboard
│   │   │   ├── Middleware/EnsureUserHasRole.php   server-side role gate
│   │   │   ├── Requests/Auth/           RegisterRequest, LoginRequest
│   │   │   └── Resources/UserResource.php
│   │   ├── Models/           User, Role, Department, Batch, Student, Faculty,
│   │   │                     Company, JobPosting, Internship, Application
│   │   ├── Policies/         StudentPolicy (placeholder, IDOR-safe default)
│   │   └── Services/Auth/    RegistrationService, AuthService (business logic
│   │                         lives here, not in controllers)
│   ├── database/migrations/  full schema, see "Database schema" below
│   ├── database/seeders/     demo data (see "Demo data")
│   └── routes/api.php        all API routes
└── frontend/                 React SPA
    └── src/
        ├── components/       ui/ (Button, Card, StatCard, Badge, Spinner),
        │                     layout/ (Sidebar, Topbar), common/ (PlaceholderPage)
        ├── layouts/           DashboardLayout (shared) + one thin wrapper per role
        ├── pages/             auth/, student/, faculty/, admin/, superadmin/
        ├── routes/            ProtectedRoute.tsx (client-side role guard)
        ├── services/          api.ts (axios + Sanctum CSRF), authService, ...
        ├── context/           AuthContext.tsx
        ├── hooks/             useAuth.ts
        ├── utils/             navConfig.ts (per-role nav), roles.ts
        └── types/             auth.ts, dashboard.ts, meta.ts
```

No business logic lives inside React components or Laravel controllers —
controllers call `app/Services`, components call `app/services` (the
frontend service layer), per the project's architecture rule.

## Database schema

- **roles** — the 4 fixed roles (`STUDENT`, `FACULTY`, `PLACEMENT_ADMIN`,
  `SUPER_ADMIN`), referenced by `users.role_id`.
- **departments**, **batches** — lookup tables.
- **users** — auth table; `role_id` FK, `is_active` flag, hashed password.
- **students** — 1:1 with `users`, FKs to `departments`, `batches`, and an
  optional `faculty_mentor_id` → `faculty.id`.
- **faculty** — 1:1 with `users`, FK to `departments`.
- **companies**, **job_postings**, **internships**, **applications** —
  placeholder tables (proper FKs/indexes are in place, but only enough
  business logic exists to power the dashboards and seed data — full
  workflows come later). `applications` uses a polymorphic
  `applicable_type`/`applicable_id` pair so one table can point at either
  a `JobPosting` or an `Internship`, with a unique constraint preventing a
  student from applying twice to the same opportunity.

Standard Laravel tables (`password_reset_tokens`, `sessions`, `cache`,
`personal_access_tokens`, the queue tables) are included too. The queue
table is named `jobs_queue` (not `jobs`) specifically so it can't collide
with the `job_postings` table naming.

## Authentication & authorization

- Laravel Sanctum, cookie-based SPA session (`withCredentials` + the
  `sanctum/csrf-cookie` handshake) — also issues a bearer token on login
  for non-browser clients.
- `POST /api/register` — student self-registration only (name, college
  email, password, roll number, department, batch). Faculty/Admin/Super
  Admin accounts are provisioned by seeding/administration, not public
  sign-up.
- `POST /api/login`, `POST /api/logout`, `GET /api/me`.
- Passwords are hashed with bcrypt via Laravel's `Hash` facade — never
  stored in plaintext.
- **Server-side authorization is enforced independently of the frontend.**
  `App\Http\Middleware\EnsureUserHasRole` gates every role-specific route
  (`role:STUDENT`, `role:PLACEMENT_ADMIN,SUPER_ADMIN`, etc.) — the React
  route guards (`ProtectedRoute.tsx`) only control what the UI *shows*.

## Roles → dashboards

| Role              | Route                    |
|-------------------|--------------------------|
| STUDENT           | `/student/dashboard`     |
| FACULTY           | `/faculty/dashboard`     |
| PLACEMENT_ADMIN   | `/admin/dashboard`       |
| SUPER_ADMIN       | `/super-admin/dashboard` |

## Installation

### Prerequisites

- Node.js 18+ and npm
- PHP 8.3+ with the `pdo_mysql` extension
- Composer 2.x
- MySQL 8+

### 1. Clone / open the project

```bash
cd CareerConnect
```

### 2. MySQL configuration

Create the database (adjust credentials to your local MySQL setup):

```sql
CREATE DATABASE careerconnect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Backend setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` and confirm the `DB_*` values match your MySQL setup
(`DB_DATABASE=careerconnect`, `DB_USERNAME`, `DB_PASSWORD`).

```bash
php artisan migrate
php artisan db:seed
php artisan serve
```

The API is now running at `http://localhost:8000`.

### 4. Frontend setup

In a second terminal:

```bash
cd frontend
npm install
cp .env.example .env    # VITE_API_URL=http://localhost:8000
npm run dev
```

The app is now running at `http://localhost:5173`.

### 5. Log in

Open `http://localhost:5173` — it redirects to `/login`. Use one of the
demo accounts below, or register a new student account.

## Demo login credentials

All seeded accounts share the password **`Password@123`**.

| Role            | Email                              |
|------------------|-------------------------------------|
| Super Admin      | superadmin@careerconnect.edu        |
| Placement Admin  | placement.admin@careerconnect.edu   |
| Faculty          | faculty1@careerconnect.edu (also faculty2, faculty3) |
| Student          | student1@careerconnect.edu (also student2 … student10) |

Seed data also includes 5 departments, 3 batches, 5 companies, 4 job
postings, 3 internships, and a handful of sample applications in varied
statuses so the dashboards aren't empty.

## Security foundation in place

- Bcrypt password hashing (`Hash::make`, `password` cast to `hashed`)
- Sanctum session auth + CSRF protection (`sanctum/csrf-cookie`,
  `EnsureFrontendRequestsAreStateful`)
- Server-side request validation (`RegisterRequest`, `LoginRequest`)
- Server-side role authorization (`EnsureUserHasRole` middleware) —
  independent of frontend route guards
- Eloquent/query builder throughout → parameterized queries, no raw SQL
  string interpolation (SQL-injection safe by default)
- `app/Policies` wired up (`StudentPolicy`) as the place IDOR/BOLA checks
  belong as more resources are exposed
- `.env` is git-ignored; no database credentials anywhere in the frontend
  (`VITE_API_URL` only — the browser only ever talks to the public API)
- CORS locked to `FRONTEND_URL` / `CORS_ALLOWED_ORIGINS`, not `*`

## Explicitly out of scope for this foundation

Excel synchronization, attendance tracking, weekly reports, evaluations,
the full application workflow (interview scheduling, offers, etc.),
placement drives, advanced reporting, AI features, alumni, and the
company portal. The relevant nav items exist as placeholders in the UI
and their tables/routes are stubbed where called for, but no business
logic behind them has been built — that's future work.
