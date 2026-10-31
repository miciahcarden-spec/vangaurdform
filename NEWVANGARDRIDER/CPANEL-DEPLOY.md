# cPanel PHP deployment

The production bundle serves the built React frontend from Apache and routes
same-origin `/api/...` requests to the native PHP API. No Node.js application
or Node runtime is required on cPanel. Node.js is used only to build the static
frontend locally.

## 1. Build the upload archive

Use Node.js 20+ and npm on the build machine:

```sh
npm ci
npm run build:cpanel
```

This creates `vanguard-velocity-php-cpanel.zip` in the workspace parent
directory. It contains the frontend files at its root, the PHP API, the MySQL
schema, and Apache/PHP configuration. It excludes
`.env`, dependency folders, and the unused legacy Node backend.

## 2. Configure cPanel

1. Select PHP 8.1 or newer for the domain's document root, and confirm the
   hosting account provides MySQL 5.7+ or MariaDB 10.2.7+.
2. Ensure `pdo_mysql`, `openssl`, and `fileinfo` are enabled. PHP cURL is
   recommended for Telegram notifications.
3. Ensure Apache `mod_rewrite` and `.htaccess` overrides are enabled.
4. Upload and extract the archive directly into the domain's **document root**.
   The root must contain `index.html`, `assets/`, `.htaccess`, and `api/`.
   Do not extract the archive into a nested directory unless that directory is
   configured as the domain document root.
5. Copy `.env.example` to `.env` and set your server-only values. Keep the
   database password and Telegram encryption key out of frontend variables.
6. Configure PHP upload settings from `.user.ini` if cPanel does not apply the
   included values. The maximum upload size is 50 MB per file and the request
   limit is set to 210 MB for the four-file rider submission.

The `.htaccess` routes `/api/...` requests to PHP, serves existing static files
directly, and falls back to `index.html` for SPA paths such as `/admin/login`.
It prevents browsers from caching the app entry page so new frontend builds are
picked up on the next visit. It also denies web access to `.env`, the database
migrations, and internal PHP bootstrap scripts. After deploying an update, do a
hard refresh; if a CDN or host cache is enabled, purge its cached site content.

## 3. Configure MySQL

Create a MySQL database and user in cPanel, grant the user access to the
database, and set these variables in cPanel's PHP application environment or
in `.env` in the document root:

- `MYSQL_HOST`: usually `localhost` on cPanel
- `MYSQL_PORT`: usually `3306`
- `MYSQL_DATABASE`: the cPanel database name
- `MYSQL_USER`: the cPanel database user
- `MYSQL_PASSWORD`: the database user's password
- `MYSQL_SSL_CA`: optional CA certificate path for remote MySQL TLS
- `APP_URL`: the public site URL, used for direct application links in
  Telegram notifications
- `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID`: optional Telegram bot and
  destination credentials. Set both to enable notifications from `.env`;
  these environment values take precedence over credentials saved in the admin
  panel.
- `TELEGRAM_SETTINGS_ENCRYPTION_KEY`: persistent base64-encoded random 32-byte
  key generated once with `openssl rand -base64 32`, required only if saving
  Telegram credentials from the admin panel

Never store database credentials or Telegram secrets in `VITE_` variables. Keep
the encryption key unchanged and backed up; Telegram credentials saved with
the key cannot be decrypted if it is lost or replaced.
Import `server/schema.sql` into the new database through phpMyAdmin's Import tab.
The same schema can be applied from cPanel Terminal by running:

```sh
php api/migrate.php
```

This creates all application tables and is safe to rerun. The MySQL schema
creates an empty database structure; it does not transfer existing records from
the former PostgreSQL database.

## 4. Verify and create the administrator

Open and verify:

- `https://your-domain.example/` — rider application
- `https://your-domain.example/api/health` — JSON health and schema status
- `https://your-domain.example/admin/login` — administrator sign-in

Create the first administrator from `/admin/login` only while there are no
administrator accounts. After the first account is created, only a signed-in
administrator can create additional accounts. Passwords are stored as bcrypt
hashes; admin sessions are random, hashed in the database, and expire after
12 hours.

The admin panel's **Notifications** section configures the Telegram bot and
destination when environment credentials are not set. Dashboard configuration
is AES-256-GCM encrypted in MySQL. Both rider and guarantor submissions trigger
notifications, and a delivery failure does not undo a saved submission.

### Troubleshooting

- An HTML 404 for `/api/health` means the request is not reaching the PHP
  rewrite. Confirm the domain document root, `.htaccess`, and `mod_rewrite`.
- A JSON 503 means PHP is running but a database setting, extension, credential,
   network access, permission, or schema needs attention. The health response
   gives a safe diagnostic category; it never includes the database password.
   SQLSTATE details are also written to the cPanel PHP error log.
- A PHP database connection error mentioning the driver means `pdo_mysql` is
  not enabled for the selected PHP version.

The production archive uses Apache and PHP only. Vite/Node is needed on the
build machine; it is not needed to run the deployed site.
