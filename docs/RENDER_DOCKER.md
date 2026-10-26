# Render deployment with Docker

This backend is Laravel 12 and requires PHP 8.2 or newer. The Docker image uses PHP 8.3 with Apache, serves only Laravel's public/ directory, enables URL rewriting, GD (JPEG/PNG/WebP), fileinfo, and MySQL PDO, and installs the system CA bundle. Apache binds to Render's PORT (default 10000). Composer installs the locked production dependencies; the separate React frontend is not built into this image.

## Create the Render web service

1. Create a Web Service from the Reclaim repository and select Docker.
2. This backend-only repository has Laravel at its root: leave Root Directory blank and use Dockerfile as the Dockerfile path. For the local monorepo layout, set Root Directory to reclaim-backend instead. The image starts Apache itself; no separate start command is needed.
3. Add the environment values below in the service's Environment settings. Do not add credentials as Docker build arguments or commit a populated .env.
4. Attach the MySQL database and supply its connection details. Use the provider's TLS CA instructions. The image includes the operating system CA bundle at /etc/ssl/certs/ca-certificates.crt; set MYSQL_ATTR_SSL_CA to that path when the database certificate chains to public roots. If the database provider gives you a private CA, add it as a Render Secret File and point MYSQL_ATTR_SSL_CA to that mounted file instead.
5. For private image uploads, either attach durable storage mounted at /var/data and set RECLAIM_IMAGE_ROOT=/var/data/reclaim-images, or configure the private Cloudflare R2 bucket using the variables below. Do not put local image directories under public/, symlink them, serve them as static files, or make the R2 bucket public. Without a persistent disk or object storage, local uploads can be lost on container replacement. Image files must be backed up separately from database records.
6. Deploy, then check the Render health path /up. Configure HTTPS and verify proxy, cookie, CORS, mail, Google sign-in and private-image behavior against the actual service before accepting users.

The Docker image does not migrate, seed, or bootstrap accounts. Back up and verify the target database, then apply the reviewed pending migrations explicitly with php artisan migrate --force from a controlled release/deploy step. Never use migrate:fresh or destructive seeders against production. Create the first administrator separately with php artisan accounts:bootstrap-admin; it prompts for a password and is not run automatically.

## Render environment values

Set these as service environment variables. Use Render's secret field for values marked secret.

| Variable | Value |
| --- | --- |
| APP_NAME | Reclaim |
| APP_ENV | production |
| APP_KEY | Generate once as shown below; secret, keep stable across deploys. |
| APP_DEBUG | false |
| APP_URL | The canonical HTTPS URL of this Laravel service. |
| APP_TIMEZONE | Asia/Manila |
| LOG_CHANNEL | stderr |
| DB_CONNECTION | mysql |
| DB_HOST | MySQL host from the database provider. |
| DB_PORT | Provider's MySQL TLS port, usually 3306. |
| DB_DATABASE | Database/schema name. |
| DB_USERNAME | Database username; secret. |
| DB_PASSWORD | Database password; secret. |
| MYSQL_ATTR_SSL_CA | /etc/ssl/certs/ca-certificates.crt, or the mounted path of a provider CA Secret File. |
| SESSION_DRIVER | database (requires the existing Laravel sessions table). |
| CACHE_STORE | database (requires the existing Laravel cache tables). |
| QUEUE_CONNECTION | database; no worker is needed for current synchronous notifications. |
| RECLAIM_FRONTEND_ORIGIN | Exact HTTPS origin of the React app, with no path or trailing slash. |
| SANCTUM_STATEFUL_DOMAINS | Exact frontend hostname, optionally with port; no scheme, e.g. app.example.com. |
| SESSION_SECURE_COOKIE | true for HTTPS. |
| SESSION_DOMAIN | Usually .example.com when the frontend and API use sibling subdomains; leave unset for same-host proxying. |
| RECLAIM_IMAGE_DRIVER | `local` by default; set to `s3` to select Cloudflare R2. |
| RECLAIM_IMAGE_ROOT | `/var/data/reclaim-images` when using a Render persistent disk. Not needed for R2. |
| RECLAIM_IMAGE_ENDPOINT | `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` from your Cloudflare account. |
| RECLAIM_IMAGE_REGION | `auto` for Cloudflare R2. |
| RECLAIM_IMAGE_BUCKET | Exact existing R2 bucket name. |
| RECLAIM_IMAGE_ACCESS_KEY_ID | R2 S3 API Access Key ID; secret environment value. |
| RECLAIM_IMAGE_SECRET_ACCESS_KEY | R2 S3 API Secret Access Key; secret environment value. |

### Private image storage with Cloudflare R2

Create an R2 API token with Object Read & Write access restricted to this bucket, then enter its Access Key ID and Secret Access Key directly as Render backend environment variables. Keep the bucket private and do not configure an `AWS_URL`, public bucket domain, R2.dev URL, or public CDN for it. The application uses the configured endpoint and Laravel's private `reclaim_images` disk; it does not issue object URLs. User and staff image requests continue through the existing authorized Laravel endpoints. Cloudflare R2 does not implement S3 ACL operations; Laravel 12.69 automatically disables visibility retention for R2 endpoints, and the private bucket policy is the access boundary. Confirm upload, authorized reads, replacement, deletion, and restoration against your actual bucket before accepting production uploads. Switching disks does not copy existing local files: copy each stored image to the same object key in R2 before cutover or its existing attachment record will point to a missing file. Keep a separate coordinated backup of R2 objects and the database attachment records; bucket durability is not a backup.

The current browser login uses Sanctum cookies. Put the frontend and API on the same site (for example, sibling subdomains under one registrable domain) or proxy /api and /sanctum through the frontend origin. Unrelated cross-site origins are not supported by the current SameSite=Lax session setup. Keep CORS and stateful-domain values exact; do not use wildcards.

### Optional integrations

For verification and password-recovery email, configure MAIL_MAILER=brevo, BREVO_API_KEY (secret), MAIL_FROM_ADDRESS (verified sender), MAIL_FROM_NAME=Reclaim, and the exact RECLAIM_FRONTEND_ORIGIN. Email delivery is required for account verification and password setup.

For Google sign-in, configure GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET (secret), and GOOGLE_REDIRECT_URI as https://<backend-host>/api/auth/google/callback. Register that exact URI in the Google OAuth web client. No Google secret belongs in React/Vite.

Render supplies PORT to the container; do not set it in the Dockerfile or expose a fixed application port in the dashboard.

## Generate APP_KEY

From reclaim-backend, run:

    php artisan key:generate --show

Copy the printed base64:... value directly into Render's APP_KEY field. Do not paste it in chat, commit it, or regenerate it on each deployment; changing it can invalidate encrypted application data and sessions. This command prints a key and does not modify .env.

## Build locally

With Docker installed, from reclaim-backend:

    docker build -t reclaim-backend .

The .dockerignore excludes .env files, Git metadata, Composer/npm dependencies, logs, test data and private image uploads from the build context. Do not add production credentials to the build context or image. The container does not include a .env file; Laravel reads service configuration from Render at runtime.

## Deployment checks not performed here

No image build or deployment was run as part of this setup. TLS CA acceptance, Render proxy/HTTPS behavior, database connectivity, migrations, durable upload persistence, backups/restores, email delivery and live Google OAuth still require verification in the configured target environment.
