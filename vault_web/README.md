# VAuLT Backend (vault_web)

The VAuLT backend is a [Yii 2](https://www.yiiframework.com/) "advanced" template
application. It's actually two separate Yii applications sharing common models
and configuration:

- **`frontend`** (Docker: port `80`, `vault-api.your-domain.example`): a JSON API
  (`frontend/controllers/ApiController.php`, routed at `/api/*`) consumed by the
  [iOS app](../vault_ios/README.md). This is the host you'll point the iOS app's
  `serverURLString` at.
- **`backend`** (Docker: port `89`, `vault.your-domain.example`): the
  browser-based authoring/admin UI where designers build quests, tasks, and
  media, and manage users.
- **`console`**: CLI commands and database migrations, run via `yii`.
- **`common`**: models, shared config, and Google OAuth credentials used by
  both apps.

## Requirements

- Docker and Docker Compose.
- *Optional:* a Google Cloud OAuth 2.0 **Web application** client, only needed for the "Sign in with Google" button. Username/password login works without it.

## Quick start (Docker)

All commands below are run from `vault_web/` unless noted.

1. **Generate local environment config.**
   ```bash
   cd vault/
   ./init
   ```
   Choose the `Development` environment.

2. **Point the app at the Docker MySQL service.** Edit
   `vault/common/config/main-local.php`: change the `dsn` host from
   `localhost` to `mysql` (the Compose service name), and match the
   username/password to `docker-compose.yml`'s `MYSQL_USER` /
   `MYSQL_PASSWORD` (defaults: `yii2advanced` / `secret`). Those env vars
   don't auto-wire into the app. This file is the only place DB credentials
   are read from.

3. **Set up Google OAuth** (see below), then return to `vault_web/`:
   ```bash
   cd ..
   docker compose up -d --build
   ```

4. **Load the schema and seed demo data:**
   ```bash
   docker compose exec -T mysql mysql -uyii2advanced -psecret yii2advanced < Database/initial-tables.sql
   docker compose exec -T mysql mysql -uyii2advanced -psecret yii2advanced < Database/starterData.sql
   ```

5. **Log in.** Visit `http://localhost:89/` for the admin UI and sign in with
   the demo account seeded in `starterData.sql`:
   - username: `admin`
   - password: `password_0`

   Change or remove this account before using the instance for anything real.

## Google OAuth setup (optional)

Only needed for the "Sign in with Google" button. If you skip it, leave the two files below unconfigured; the Google
button will just error when tapped.

One Google Cloud OAuth **Web application** client, used in two places on the
backend, plus a paired **iOS** client for the mobile app (see
[vault_ios/README.md](../vault_ios/README.md)):

1. Copy `vault/common/config/google_credentials.json.example` to
   `google_credentials.json` in the same directory, and fill in your web
   client's `client_id`, `project_id`, and `client_secret`.
2. Copy `vault/frontend/config/client_secret.json.example` to
   `client_secret.json` in the same directory, and fill in the same
   `client_id` / `client_secret`.
3. The same web client's ID also goes into the iOS app's `AppDelegate.swift`
   as `serverClientID`. See the iOS README for the full list of matching
   placeholders.

## Keeping `composer.json` in sync

There are **two** `composer.json` files:

- `vault/composer.json`: used for local development (`composer install`
  outside Docker).
- `docker-build/composer.json`: copied into the Docker image during build,
  before the `vault/` source is mounted in.

## Quest progress and Canvas integration

The frontend also exposes authenticated `GET/POST /api/v1/quests/{quest_id}/progress`
for reading and saving task completion. A standalone Node.js gateway sends this
progress to Canvas as an assignment grade. See [gateway setup and API examples](../canvas_gateway/README.md).

## Directory layout

```
vault/
  common/       shared config, models, and Google OAuth credentials
  console/      CLI commands and database migrations
  backend/      browser-based authoring/admin app (port 89)
  frontend/     JSON API consumed by the iOS app, at /api/* (port 80)
  environments/ environment-specific config templates (used by ./init)
  vendor/       Composer dependencies
docker-build/   Dockerfile, Apache vhost config, and the Docker-image composer.json
Database/       SQL schema (initial-tables.sql), demo seed data (starterData.sql)
docker-compose.yml
```

`vault/LICENSE.md` covers the original [Yii 2 Advanced Project
Template](https://github.com/yiisoft/yii2-app-advanced) scaffold this project
was built on (`init`, `environments/`, etc.). VAuLT's own code is licensed
under the root [`LICENSE.txt`](../LICENSE.txt) (Apache 2.0), per
[`NOTICE`](../NOTICE).
