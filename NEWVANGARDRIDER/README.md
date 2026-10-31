# Vanguard Velocity Rider Applications

The application, administrator accounts/sessions, and private documents are
stored in MySQL or MariaDB. The production backend is native PHP and has no
Supabase or Node.js runtime dependency.

## Local setup

1. Use MySQL 5.7+ or MariaDB 10.2.7+, and PHP 8.1+ with `pdo_mysql`,
   `openssl`, and `fileinfo`, plus Node.js 20+ and npm for the frontend build.
2. Copy `.env.example` to `.env` and configure the MySQL connection. Never add
   server secrets to `VITE_` variables.
3. Import the complete [MySQL schema](./server/schema.sql) into the selected
   database using phpMyAdmin, or run `npm run db:migrate` from the project
   directory.
5. Build the frontend with `npm run build`, then run both local servers with
   `npm run dev:all`. Vite serves the frontend and proxies `/api` to PHP.
6. Create the first admin account from `/admin/login`. Initial registration is
   available only when there are no admins; later account creation requires an
   authenticated admin. Passwords are stored as bcrypt hashes.

For cPanel deployment, run `npm run build:cpanel` and follow
[CPANEL-DEPLOY.md](./CPANEL-DEPLOY.md). The archive has the static frontend at
its root and needs Apache with PHP, not a Node.js application.

## Application features

Rider and guarantor submissions, administrator authentication and review,
form-content editing, company branding, private document storage, and Telegram
notifications use the same PHP/MySQL API. Uploaded documents are stored as
private blob records, with a 50 MB per-file limit. Riders and
guarantors must provide an exactly 11-digit NIN and scan their NIN document;
riders scan both sides of their identity card, while guarantors scan the front,
in addition to required video verification. Only an authenticated
administrator can retrieve uploaded documents.

Administrators can review applicant and individual guarantor records, print
the application and attached documents, and view captured applicant locations
on the dashboard map. The **Form content** section edits published frontend
copy and company contact details; the logo can be uploaded or removed there.
Telegram delivery can be configured in the admin panel or with
`TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` in the server `.env`. Set `APP_URL`
to the public site URL so Telegram messages link directly to each application.
Submission responses are sent before Telegram delivery is attempted, so a slow
notification service does not hold up the applicant's success screen.

Location is acquired in the background while a rider or guarantor completes
the form to reduce submission wait time. When the browser provides coordinates,
latitude, longitude, reported accuracy, and capture time are stored in separate
database columns. Location accuracy is device-reported and is not guaranteed.
Application submission does not wait for or require a location fix; location
fields remain empty if the device or browser cannot provide coordinates. Rider
and guarantor forms capture residential state/city and state of
origin/city/address. Address suggestions are provided by Photon (OpenStreetMap);
typed searches are sent to that service, and selecting a suggestion is optional.

## Checks

- `npm run typecheck`
- `npm run lint`
- `npm run build`
- `php -l api/index.php`
- `php -l api/migrate.php`
