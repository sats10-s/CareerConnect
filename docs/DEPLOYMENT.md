# CareerConnect: Deployment Guide

Written 2026-10-08 against the `student-import` branch. The example uses one Ubuntu 24.04 server running Nginx, PHP-FPM, MySQL 8 and Supervisor, with the frontend and the API on the same domain (`placements.example.edu` below; replace with yours). One domain keeps Sanctum's cookie sign-in simple and avoids CORS problems.

## 1. Before you deploy

The code is on GitHub at `git@github.com:sats10-s/CareerConnect.git` (branch `student-import`), with `composer.json`, `composer.lock` and the status-history migration fix committed. Two rules still apply:

1. **Never run `php artisan db:seed` in production.** The full seeder creates demo accounts that all share one published password. Run only the reference-data seeders listed in step 4.4.
2. **Clear out stale import files.** `backend/storage/app/private/student-imports/` on the development machine holds a student file uploaded on 2026-10-06 from an import that was never confirmed or cancelled. It is git-ignored; cancel that import in Student Data, or delete the file if it is not needed.

## 2. Server requirements

| Component | Version / setting |
|---|---|
| PHP | 8.3 or newer, with `pdo_mysql`, `mbstring`, `xml`, `zip`, `gd`, `intl`, `bcmath`, `curl`, `fileinfo` |
| Composer | 2.x |
| Node.js | 20 LTS or newer (only to build the frontend) |
| MySQL | 8.0+, `utf8mb4` |
| Nginx | any current version |
| Supervisor | for the queue worker |
| PHP upload limits | `upload_max_filesize = 12M`, `post_max_size = 16M` (imports accept up to 10 MB, resumes 5 MB; PHP's default is 2 MB) |
| Nginx body limit | `client_max_body_size 16m;` |

## 3. Database

```sql
CREATE DATABASE careerconnect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'careerconnect'@'localhost' IDENTIFIED BY '<strong password>';
GRANT ALL PRIVILEGES ON careerconnect.* TO 'careerconnect'@'localhost';
FLUSH PRIVILEGES;
```

## 4. Backend (Laravel API)

### 4.1 Get the code

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone -b student-import https://github.com/sats10-s/CareerConnect.git careerconnect
sudo chown -R www-data:www-data careerconnect
cd careerconnect/backend
composer install --no-dev --optimize-autoloader
```

### 4.2 Production `.env`

Copy `.env.example` to `.env`, then set at least:

```dotenv
APP_NAME=CareerConnect
APP_ENV=production
APP_DEBUG=false
APP_URL=https://placements.example.edu
FRONTEND_URL=https://placements.example.edu

LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=careerconnect
DB_USERNAME=careerconnect
DB_PASSWORD=<strong password>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=placements.example.edu

CACHE_STORE=database
QUEUE_CONNECTION=database

SANCTUM_STATEFUL_DOMAINS=placements.example.edu
CORS_ALLOWED_ORIGINS=https://placements.example.edu

# Real SMTP: forgot-password links are sent by email
MAIL_MAILER=smtp
MAIL_HOST=<smtp host>
MAIL_PORT=587
MAIL_USERNAME=<smtp user>
MAIL_PASSWORD=<smtp password>
# Leave MAIL_SCHEME unset for port 587 (STARTTLS); use MAIL_SCHEME=smtps for port 465
MAIL_FROM_ADDRESS=placements@example.edu
MAIL_FROM_NAME="CareerConnect"
```

`FRONTEND_URL` matters: password reset emails link to `FRONTEND_URL/reset-password`.

### 4.3 Key, migrations, caches

```bash
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

Re-run `php artisan config:cache` every time you change `.env`.

### 4.4 Reference data and the first Super Admin

Seed only the lookup tables (all are safe to re-run):

```bash
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=DepartmentSeeder --force
php artisan db:seed --class=DepartmentAliasSeeder --force
```

Check the department list in `database/seeders/DepartmentSeeder.php` matches your college first, or edit departments later under Super Admin > Departments. Batches are created automatically by the student import, so `BatchSeeder` is optional.

Create the first Super Admin (you type the password; it is hashed on save):

```bash
php artisan tinker
>>> App\Models\User::create(['name' => 'Placement Office', 'email' => 'tpo@example.edu', 'password' => '<choose one>', 'role_id' => App\Models\Role::where('name', 'SUPER_ADMIN')->value('id'), 'is_active' => true]);
```

Everyone else is created from inside the app (see section 8).

### 4.5 Queue worker (required)

Confirming a Student Data import runs as a background job. Without a worker, imports sit at "Import started" forever. Create `/etc/supervisor/conf.d/careerconnect-worker.conf`:

```ini
[program:careerconnect-worker]
command=php /var/www/careerconnect/backend/artisan queue:work --sleep=3 --tries=1 --timeout=900
user=www-data
autostart=true
autorestart=true
stopwaitsecs=900
redirect_stderr=true
stdout_logfile=/var/www/careerconnect/backend/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start careerconnect-worker
```

There is no scheduled task yet, so no cron entry is needed.

## 5. Frontend (React SPA)

Build on the server or on your machine, then copy `frontend/dist` to the server:

```bash
cd /var/www/careerconnect/frontend
echo "VITE_API_URL=https://placements.example.edu" > .env.production
npm ci
npm run build
```

`VITE_API_URL` is baked in at build time, so rebuild whenever it changes.

## 6. Nginx

```nginx
server {
    listen 80;
    server_name placements.example.edu;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name placements.example.edu;

    ssl_certificate     /etc/letsencrypt/live/placements.example.edu/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/placements.example.edu/privkey.pem;

    client_max_body_size 16m;

    root /var/www/careerconnect/frontend/dist;
    index index.html;

    # Laravel: API, Sanctum CSRF cookie, health check
    location ~ ^/(api|sanctum|up)(/|$) {
        root /var/www/careerconnect/backend/public;
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        root /var/www/careerconnect/backend/public;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # React Router: every other path serves the SPA
    location / {
        try_files $uri /index.html;
    }
}
```

Get the certificate with `sudo certbot --nginx -d placements.example.edu`. Adjust the PHP-FPM socket to your installed version.

## 7. Verify the deployment

1. `https://placements.example.edu/up` returns 200.
2. Sign in as the Super Admin; the dashboard loads.
3. Super Admin > Settings: set the institution name, support email and academic year. Leave student self-registration off.
4. Super Admin > Student Data: upload the college file, check the preview, confirm, and watch the progress bar reach 100% (this proves the queue worker runs).
5. Sign in as one imported student with JNTU No and mobile number; you should be forced to set a new password and personal email.
6. Use Forgot password for that student and confirm the email arrives (this proves SMTP).
7. Open `/company/register`, register a test company, approve it under Company Approvals, sign in as the recruiter.
8. Placement Admin > Excel Sync: download one export and open it in Excel.

## 8. How each role gets in

No real passwords belong in this document or in the repository.

| Role | Created by | First sign-in |
|---|---|---|
| Super Admin | Section 4.4, at install | Email + the password chosen at install |
| Placement Admin | Super Admin > Student Data > Placement admins (Excel: Name, Department, College Email, Password) or Super Admin > Users | College email + password from the file; must change it at first sign-in |
| Faculty | Super Admin > Users | Email + password set by the Super Admin |
| Student | Super Admin > Student Data import | JNTU No + mobile number; must set a new password and personal email. Students without a valid mobile get a one-time password shown once to the Super Admin |
| Company recruiter | Self sign-up at `/company/register`, approved by Super Admin; teammates added by the owner | Work email + their own password |

Distribute Placement Admin import files and one-time passwords privately, and delete the Excel file once the import is confirmed.

## 9. Updating to a new version

```bash
cd /var/www/careerconnect
sudo -u www-data git pull
cd backend
composer install --no-dev --optimize-autoloader
php artisan down
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
cd ../frontend && npm ci && npm run build
```

## 10. Backups and operations

- **Database**: nightly `mysqldump --single-transaction careerconnect | gzip > /backups/careerconnect-$(date +%F).sql.gz`, kept off the server.
- **Files**: back up `backend/storage/app/private/` (student resumes and internship documents).
- **Logs**: `backend/storage/logs/laravel.log` and `worker.log`. Set up log rotation.
- **Security**: keep `APP_DEBUG=false`; never commit `.env`; review Super Admin > Audit Logs for repeated failed sign-ins.
