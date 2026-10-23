# CareerConnect on Render (single Docker service)

Copy `Dockerfile`, `.dockerignore`, `render.yaml`, and the `deploy/` directory into the repository root. Commit and push them to GitHub.

In Render, choose **New > Blueprint**, select the repository and branch, and apply `render.yaml`. Add the environment variables marked `sync: false` in the Render dashboard. Keep the real `APP_KEY` private; generate one locally with `php artisan key:generate --show` if needed. Do not use the development database password.

Set `APP_URL` and `FRONTEND_URL` to the same Render URL, for example `https://careerconnect.onrender.com`. Set `SANCTUM_STATEFUL_DOMAINS=careerconnect.onrender.com` and `CORS_ALLOWED_ORIGINS=https://careerconnect.onrender.com`.

The external MySQL server must allow connections from Render and must already contain an empty database. On each deploy the container runs `php artisan migrate --force`, then starts Nginx, PHP-FPM, and the Laravel queue worker. The health check is `GET /up`.

Render's filesystem is ephemeral. Uploaded student-import files need persistent object storage (S3-compatible storage or another external service) before relying on them in production.

Rotate the MySQL password and APP_KEY that were pasted into chat or used in development. Never commit `.env` or put secrets in `render.yaml`.
