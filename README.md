# AcadBest Backend

Laravel API for the AcadBest Angular portal. The API uses database-backed roles, bearer tokens, and server-side authorization for protected operations.

## Local setup

From this directory in PowerShell:

```powershell
if (!(Test-Path .env)) { Copy-Item .env.example .env }
composer install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve --host 0.0.0.0 --port 8000
```

The default database is SQLite at `database/database.sqlite`. An existing `.env` is preserved by the setup command; verify its `DB_*` settings before migrating. To use MySQL, configure `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. Set `FRONTEND_URL` to the Angular origin if it is not `http://localhost:4200`.

## Demo accounts

`php artisan db:seed` creates the four role accounts. They share the development-only password `AcadBest-Demo-2026!` unless `DEMO_PASSWORD` is set in `.env`.

| Role | E-mail |
|---|---|
| Student | `student@aces.edu.ph` |
| Faculty | `faculty@aces.edu.ph` |
| Registrar Staff | `registrar@aces.edu.ph` |
| Admin | `admin@aces.edu.ph` |

Change the demo password before using these accounts outside local development. The seeder creates the Student account's required academic profile, but no sample grades, requests, applications, announcements, or extra accounts; portal records are managed through the UI/API.

## API

User, enrollment, and service-request list endpoints are paginated (`25` records by default, capped at `50`) and accept `page` and `search` query parameters. Enrollment lists also accept `status`; service-request lists accept `status` and `type`. The Angular client requests additional pages as needed instead of downloading the complete lists.

The Angular client connects to `http://localhost:8000/api`. Public routes allow login, online enrollment submission, and application tracking. Protected routes require `Authorization: Bearer <token>` and enforce role authorization server-side. `GET /api/portal` supplies role-scoped data; accounts, enrollments, courses, grades, announcements, requests, notifications, and settings use resource-specific routes in `routes/api.php`.

## Production configuration

Set valid database host, port, database name, and credentials in `.env` before migrating. Database connection attempts use `DB_CONNECT_TIMEOUT` (default `5` seconds); persistent PDO connections remain disabled unless `DB_PERSISTENT=true` is explicitly appropriate for the deployment. Slow SQL statements are logged when they exceed `DB_SLOW_QUERY_MS` (default `250` milliseconds).

For production deployments, run `php artisan optimize` as part of the release process to cache configuration, routes, and framework metadata. Do not clear caches during ordinary requests; use `php artisan optimize:clear` only when intentionally invalidating deployment caches.
