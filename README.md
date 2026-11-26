# Cairn

Cairn is an issue tracker built for a web agency and its clients. A single instance hosts every client: each one has its own workspace, which groups its projects.

![The issues of a project, with their category, step, criticality and assignee](docs/screenshots/issues.jpg)

| An issue | The kanban of a category |
|---|---|
| ![An issue: description, files, activity, fields and links](docs/screenshots/issue.jpg) | ![The kanban of the bugs of a project, one column per step](docs/screenshots/kanban.jpg) |

- **Workflows that fit the way you work**: bug, feature, internal task… Each type of issue follows its own path, with its own steps, which you design yourself in the back office. Your team moves issues freely from step to step; your clients get the few actions you choose for them, and nothing else.
- **The big picture at a glance**: filter, sort and group your issues, bookmark your views and share them with a simple link. Switch to the kanban to move work forward by drag and drop, or handle dozens of issues at once.
- **Clear conversations**: formatted descriptions and comments, mentions of colleagues, references between issues, attachments. Plan your deliveries by release when the project needs it.
- **Always informed, never overwhelmed**: everyone receives by email the news of the issues they follow, grouped so as not to clutter their inbox.
- **Code linked to issues**: GitLab commits and merge requests that mention an issue show up right on it. GitHub is coming soon.
- **Switch tools without losing anything**: bring over the history of Redmine, Jira or any other tool, with its issues, comments and files, their dates and their authors. A guided screen helps you map the old tool to the new one, without notifying anyone.
- **Automations without code**: follow up on an issue left unanswered, assign new bugs automatically, subscribe the right person… Each project has its own rules, built in a few clicks.
- **Transparent budgets**: track the time sold to the client, in days or hours, and how much of it has been used. The client sees the same balance as you, so there are no unpleasant surprises.
- **A new project ready in no time**: start from a template that already brings its categories and automations.
- **Sign in with your existing accounts**: optionally, your teams sign in with Authelia, Keycloak or any OpenID Connect provider.
- **Open to your tools and to AI**: connect your scripts through the REST API and your AI assistants through the MCP server. They never have more rights than the person using them.

The interface is available in English and French; each instance runs in one language.

## Stack

Symfony 8.2 on PHP 8.5, Doctrine ORM on PostgreSQL, Messenger for emails and scheduled tasks, the Symfony Workflow component for workflows built from the configuration, Twig with Symfony UX (Turbo, Live Components, Stimulus), Tailwind CSS 4 and Vite through [Symfony Reprise](https://github.com/symfony/reprise), and [API Platform](https://api-platform.com) for the REST API.

## Status

Cairn has no release yet and is not ready for production. Among other things, it depends on Symfony 8.2, which is still in beta (`minimum-stability: beta` in `composer.json`).

## Installation

This section explains how to deploy Cairn, either from its Docker image or on your own server. For development, see [Local development](#local-development) instead.

### With Docker

The image `ghcr.io/julienj/cairn` contains the application, its dependencies and the built assets, served by FrankenPHP. The same image runs both the web server and the worker. It needs PostgreSQL and is available for amd64 and arm64. [`compose.prod.yaml`](compose.prod.yaml) is a starting point with the web server, the worker and PostgreSQL.

Copy it into a folder, with a `.env` file next to it:

```dotenv
APP_SECRET=<random string, e.g. openssl rand -hex 32>
MERCURE_JWT_SECRET=<another random string, at least 32 characters>
POSTGRES_PASSWORD=<database password>
DEFAULT_URI=https://cairn.example.com
SERVER_NAME=cairn.example.com
TZ=Europe/Paris
```

`TZ` is the timezone of the instance (UTC if unset).

Then start the stack and create the first administrator:

```bash
docker compose -f compose.prod.yaml up -d
docker compose -f compose.prod.yaml exec web bin/console app:user:create
```

On startup, the web server creates the database schema and the worker waits for it to finish. Then sign in: the administration offers to install the starter configuration.

**HTTPS.** Depending on your setup:

| Setup | Variables |
|---|---|
| Public domain name (automatic certificate, ports 80 and 443 open) | `SERVER_NAME=cairn.example.com` |
| Behind a reverse proxy that handles the certificate | `SERVER_NAME=:80`<br>`SYMFONY_TRUSTED_PROXIES=private_ranges`<br>`MERCURE_URL=http://web/.well-known/mercure` |
| Trying it locally | the three above, plus<br>`DEFAULT_URI=http://localhost:8080`<br>`HTTP_PORT=8080` |

**Updating.** Make a backup, then run `docker compose pull` and `docker compose up -d`. Migrations are applied automatically on startup. To run them yourself, set `CAIRN_AUTO_MIGRATE=0` and run `bin/console doctrine:migrations:migrate`.

| Tag | Contents |
|---|---|
| `latest` | the latest release |
| `0.x.y` | a specific release |
| `edge` | the main branch |

**Data.** Everything that matters is in the database and the `/data` volume: back them up together (see [Backups](#operations)).

**Configuration.** The variables of the [Environment](#environment) section are passed to both containers. Real time is enabled by default; to disable it, leave `MERCURE_URL` empty (see [Real time](#real-time-optional)).

**Without Compose (Kubernetes, Nomad…).** One image, started twice with a different command:

| Container | Command | Listens on |
|---|---|---|
| Web | the default one | port 8080, over HTTP |
| Worker | `bin/console messenger:consume async scheduler_default --time-limit=3600` | nothing |

What you need to provide:

- **A shared `/data` volume** between the two containers, plus a writable `/tmp`. The rest of the filesystem can be read-only, and the image does not run as root.
- **A healthcheck** on `/healthz`, which answers even when the database is unavailable.
- **Several web containers?** Run the migrations once per deployment (`bin/console doctrine:migrations:migrate`) instead of using `CAIRN_AUTO_MIGRATE=1`, and use a separate Mercure hub: the built-in one serves a single container.

### On a server

#### Requirements

- PHP 8.5 with the `intl`, `pdo_pgsql`, `ctype`, `iconv` and `zip` extensions, and Composer
- PostgreSQL 17 or 18
- Node.js 22, only to build the assets
- A web server pointing at `public/` (Caddy, FrankenPHP, nginx + PHP-FPM…), with PHP upload limits that allow 10 MB files (`upload_max_filesize`, `post_max_size`)

#### Steps

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build                   # assets into public/build/

bin/console doctrine:database:create --if-not-exists
bin/console doctrine:migrations:migrate   # the schema; run again after every update
bin/console app:config:install            # starter configuration: roles, workflows, steps, fields
bin/console app:user:create               # first administrator
```

Besides the web server, a worker must run at all times, under a process manager such as systemd or supervisor (see [Operations](#operations)). It sends notification emails and runs scheduled tasks, such as removing abandoned attachments:

```bash
bin/console messenger:consume async scheduler_default --time-limit=3600
```

### Environment

`.env` holds the default values. Put the real ones in `.env.local` (ignored by git); with Docker, set them as environment variables of the containers:

```dotenv
APP_ENV=prod
APP_SECRET=<random string>
DATABASE_URL="postgresql://user:password@127.0.0.1:5432/cairn?serverVersion=18&charset=utf8"
# Public URL, used in email links and forge webhooks
DEFAULT_URI=https://cairn.example
# Behind a reverse proxy or a load balancer. Without these, every request
# seems to come from the proxy: sign-in throttling treats all users as a
# single address, and HTTPS is not detected
SYMFONY_TRUSTED_PROXIES=private_ranges
SYMFONY_TRUSTED_HEADERS=x-forwarded-for,x-forwarded-proto,x-forwarded-host,x-forwarded-port
MAILER_DSN=smtp://user:password@smtp.example.com:587
MAILER_FROM="Cairn <no-reply@cairn.example>"
# Language of the instance (en or fr)
CAIRN_LOCALE=en
# Where attachments are stored, outside public/
ATTACHMENTS_DIR=/var/lib/cairn/attachments
# Optional: how GitLab delivers to Cairn, see below
FORGE_GITLAB_URL=https://gitlab.example
FORGE_GITLAB_TOKEN=<token with the api scope>
FORGE_GITLAB_HOOK_SECRET=<random string>
# Where archives wait to be imported (Administration > Imports)
IMPORTS_DIR=/var/lib/cairn/imports
# Optional: single sign-on, see below
OIDC_PROVIDER_URI=https://accounts.google.com
OIDC_CLIENT_ID=<client id>
OIDC_CLIENT_SECRET=<client secret>
OIDC_NAME=Google
# Optional: real time, see below
MERCURE_URL=http://127.0.0.1/.well-known/mercure
MERCURE_PUBLIC_URL=https://cairn.example/.well-known/mercure
MERCURE_JWT_SECRET=<random string of 32 characters or more>
MERCURE_JWT_ISSUER=https://cairn.example
```

### Single sign-on (optional)

Cairn can delegate sign-in to an OpenID Connect provider (Google, Keycloak, Authelia…). To enable it:

1. Register Cairn with the provider, using the redirect URI `https://<your domain>/login/sso/callback`.
2. Set the variables:

   | Variable | Value |
   |---|---|
   | `OIDC_PROVIDER_URI` | the provider's issuer, e.g. `https://accounts.google.com` |
   | `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` | given by the provider |
   | `OIDC_NAME` | the name shown on the sign-in button |

3. In the administration, switch the relevant accounts to single sign-on.

Good to know:

- **Accounts are not created on the fly**: only accounts that already exist in Cairn can sign in, and the provider grants no role.
- **The provider must send `email_verified: true`**: this is what links its user to the Cairn account the first time. Entra ID does not send it, so it does not work.
- **One account, one method**: an account that uses single sign-on has no password.
- **Remember to deactivate accounts in Cairn**: removing someone from the directory closes neither their session nor their API tokens.

### GitLab webhooks (optional)

A project manager links a repository in the settings of a project (Repositories), by its path. From then on, the commits and merge requests of that repository that mention an issue of the project show up on it. A repository nobody linked never leaves anything in Cairn. GitLab can deliver to Cairn in three ways:

| Way | In GitLab | What Cairn holds |
|---|---|---|
| By hand (default) | For each repository, a Maintainer adds a webhook with the address and the secret shown by Cairn | A secret per repository |
| Instance token | Once: a token with the `api` scope, for an account that is Maintainer of the repositories. Cairn then installs each webhook itself | A secret per repository, and a token that can read and write those repositories |
| Instance hook | Once: a system hook, as an administrator | One secret for the whole instance, and no access to GitLab |

For the instance token, set `FORGE_GITLAB_URL` and `FORGE_GITLAB_TOKEN`.

The instance hook is for a self-managed GitLab (system hooks exist in every tier, but not on GitLab.com). To set it up:

1. Generate a secret, for example with `openssl rand -hex 32`.
2. In GitLab, as an administrator: **Admin** > **System hooks** > **Add new webhook**.
3. Set **URL** to `https://<your domain>/webhook/gitlab` and **Secret token** to the secret. Under **Trigger**, select **Push events** and **Merge request events** only. Leave **Enable SSL verification** selected.
4. Set `FORGE_GITLAB_HOOK_SECRET` to the same secret and restart Cairn.

Linking a repository in Cairn is then all there is to do: nothing is installed in GitLab, and the settings of a project show neither an address nor a secret. Repositories that already have a webhook of their own keep working.

**Security limits of the instance hook.** It replaces the secret of each repository with a single secret shared by the whole GitLab instance:

- **One secret protects every linked repository.** Whoever knows it can send Cairn forged commits and merge requests in the name of any linked repository. These references stay private, notify nobody and move no issue, but they show on the issues they mention. If the secret leaks, change it in GitLab and in Cairn.
- **Linking a repository no longer proves control over it.** With a secret per repository, only a Maintainer of the repository can add the webhook in GitLab. With the instance hook, anyone who may configure a project in Cairn can link any repository of the GitLab instance to it, and see the commits and merge requests of that repository that mention an issue of their project.

In return, Cairn holds no access token to GitLab. Prefer a secret per repository when people outside your team may configure projects, or when GitLab hosts repositories whose activity must not leave it.

### Widget (optional)

The widget lets the people of a project report a problem from the site being reviewed, without leaving it: a button, a panel with a short form, a screenshot, and a technical report (page, browser and system preferences, console errors, failed requests, Ajax or files, performance, pages seen). Everyone signs in with their own Cairn account, password or single sign-on, and creates the issue with their own rights. There is nothing to enable on the instance.

1. In the project, open **Configuration → Widget** and declare the site by its address (`https://staging.example.com`).
2. Copy the line of script shown there into the `<head>` of every page of the site, as early as possible:

   ```html
   <script src="https://cairn.example.com/widget.js" data-project="MD-SPT" data-display="always" async></script>
   ```

   `data-display="always"` shows the button to everybody: it is for a site being reviewed. Without it (see below) the button is shown on demand.

3. Optionally, say what the site knows of itself, in names and texts of your choosing: they go into the technical report of each issue. Push objects into `window.Cairn`, before or after the widget is loaded, as many times as you want; a later name replaces an earlier one, and `undefined` takes it away. Values are shown as text, `null` included: an object inside is laid out under dotted names (`user.id`, `user.email`), a list is written on one line, a function is left out. From the layout of a Symfony site:

   ```twig
   <script nonce="{{ csp_nonce('script') }}">
       (window.Cairn ||= []).push({{ {
           version: app_version,
           environment: app.environment,
           user: app.user ? app.user.userIdentifier : null,
       }|json_encode|raw }});
   </script>
   ```

   And from the JavaScript of the page, when something changes: `window.Cairn.push({ cart: '3 items' })`. The `nonce` is only needed under a Content-Security-Policy (`csp_nonce()` comes with NelmioSecurityBundle). What is pushed is shown to whoever sees the issue, external users included: never push a secret.

**In production**, declare the site the same way and leave `data-display` out:

```html
<script src="https://cairn.example.com/widget.js" data-project="MD-SPT" async></script>
```

Nobody sees the button, and the script does nothing (it listens to nothing, stores nothing, asks Cairn nothing), until someone opens a page of the site with `?cairn=1` at the end of its address. The site then remembers them in that browser, and the widget follows them from page to page as on a site being reviewed; `?cairn=0` hides it again. The parameter is a courtesy to the visitors, not a secret: whoever adds it gets the button, then the sign-in page of Cairn, and nothing else.

Good to know:

- **The button can be moved** by dragging it anywhere along the edge of the window, when it hides what is being reviewed: it catches on the corners and on the middle of each edge, and its panel opens beside it. The site remembers the place, per browser.
- **The button only shows on the sites declared on the project.** On any other site the script shows nothing, whatever its tag says.
- **The screenshot needs HTTPS** on the site, as browsers require. Without it the widget works and offers to attach a file instead.
- **A Content-Security-Policy on the site** must allow Cairn in `script-src`, `frame-src` and `connect-src`. A `Permissions-Policy` that names `display-capture` must allow Cairn there too, or the screenshot is refused.
- **Behind a proxy**, let the answers under `/widget/` keep their own `Content-Security-Policy` (it names the sites allowed to frame the widget) and do not add an `X-Frame-Options` to them; pass the `Authorization` header through.
- **What the script notes** stays in the page until someone reports a problem, and is shown to them before it is sent: the errors of the page, its requests that failed, Ajax or files (kind, method, address, status: never what they carried), the pages seen in the tab, all addresses without their parameters, how the page performed (server answer, load, Core Web Vitals), and the zoom and preferences of the system (theme, pointer, reduced motion, contrast).
- Someone who linked their account on a site stays linked for 30 days in that browser. Removing the site from the project, or deactivating the account, ends it at once.

### Real time (optional)

With a [Mercure](https://mercure.rocks) hub, lists and kanbans update when someone else changes an issue, without reloading the page. With the Docker image, it is already on thanks to the hub built into FrankenPHP: there is nothing to do.

For another hub, set:

| Variable | Value |
|---|---|
| `MERCURE_URL` | the address Cairn uses to publish |
| `MERCURE_PUBLIC_URL` | the address browsers use to subscribe |
| `MERCURE_JWT_SECRET` | the key that signs the tokens, shared with the hub |
| `MERCURE_JWT_ISSUER` | the issuer of the tokens, usually Cairn's URL |

On the hub side, reject anonymous subscribers (no `anonymous` directive) and, if it is not on the same domain as Cairn, allow Cairn with `cors_origins`. Example Caddy configuration: [`docker/Caddyfile`](docker/Caddyfile).

Good to know: the hub only carries a signal ("an issue of this project changed"), never any data; each page then reloads what its viewer is allowed to see. The worker publishes these signals: without it, there is no real time. To disable real time, leave `MERCURE_URL` empty.

### Operations

**The worker must always run.** Without it, no email is sent and no scheduled task runs. Example systemd unit:

```ini
# /etc/systemd/system/cairn-worker.service
[Unit]
Description=Cairn worker (emails, scheduled tasks)
After=network.target postgresql.service

[Service]
User=www-data
WorkingDirectory=/var/www/cairn
ExecStart=/usr/bin/php bin/console messenger:consume async scheduler_default --time-limit=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

The worker stops every hour (`--time-limit`) and systemd restarts it, which frees its memory. After a deployment, run `bin/console messenger:stop-workers` so that it loads the new code.

**Backups.** Back up the database and `ATTACHMENTS_DIR` (attachments and profile pictures) together: an attachment whose file is missing can no longer be downloaded. `IMPORTS_DIR` does not need to be backed up.

**HTTPS.** Serve Cairn over HTTPS only. Cairn sends its own security headers, except `Strict-Transport-Security`, which you add on the web server. Caddy and FrankenPHP redirect to HTTPS on their own; with nginx:

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
```

## Local development

All you need is Docker (with Compose v2) and [Castor](https://castor.jolicode.com/getting-started/installation/): PHP, Composer, Node.js and PostgreSQL run in containers.

```bash
castor install
```

This builds the images, installs the dependencies, creates the databases, loads the demo data and starts everything. Cairn is then available at <http://localhost:8000>. After that, `castor start` and `castor stop` are enough.

### Test accounts

The password is `cairn` for everyone. `castor db:fixtures` resets the data.

Internal users:

| Account | Who | Where |
|---|---|---|
| `admin@cairn.test` | Camille Vasseur, administrator | Everything, and the administration |
| `thomas.girard@cairn.test` | Thomas Girard, project manager | Maison Dupont (`MD`) and Vallier Group (`GV`) |
| `yann.leroy@cairn.test` | Yann Leroy, project manager | Natura Cosmetics (`NC`) and Travelia (`TR`, archived) |
| `nadia.benali@cairn.test` | Nadia Benali, developer | Online shop (`MD-SPT`), Mobile app (`GV-APP`) |
| `lea.fontaine@cairn.test` | Léa Fontaine, developer, **through Authelia** | Online shop (`MD-SPT`), Supplier portal (`GV-PRT`), Back-office (`NC-BO`) |
| `marc.petit@cairn.test` | Marc Petit, developer | Intranet (`MD-INT`), Supplier portal (`GV-PRT`), Booking site (`TR-RES`) |

External users (clients):

| Account | Who | Where |
|---|---|---|
| `claire.martin@maison-dupont.test` | Claire Martin, client | Maison Dupont |
| `paul.roussel@maison-dupont.test` | Paul Roussel, client, read-only | Maison Dupont |
| `ines.garnier@vallier.test` | Inès Garnier, client | Vallier Group |
| `remi.colin@natura.test` | Rémi Colin, client | Natura Cosmetics |
| `sophie.bernard@travelia.test` | Sophie Bernard, client | Travelia (archived) |

For a first tour, open Maison Dupont's online shop (`MD-SPT`), the most complete project, as Thomas and then as Claire: you will see the difference between what the team sees and what the client sees.

### Single sign-on

The stack ships [Authelia](https://www.authelia.com) as its identity provider: click "Sign in with Authelia" on the sign-in page. The first time you reach <https://authelia.localhost:9443>, the browser warns about an unknown certificate: accept it.

| Authelia user | Password | Result |
|---|---|---|
| `lea` | `cairn` | signed in as Léa Fontaine |
| `hugo` | `cairn` | refused: no account in Cairn |

To let another account sign in through Authelia: switch it to single sign-on in the administration, add a user with the same email to `docker/authelia/users_database.yml`, then run `castor docker:restart authelia`.

### Services

| Service | Role | Address |
|---|---|---|
| `php` | the application (FrankenPHP), with the Mercure hub | <http://localhost:8000> |
| `messenger` | the worker: emails, real time, scheduled tasks | |
| `vite` | the assets, with hot reload | port 5173 |
| `widget` | the script of the widget, built again when it changes | <http://localhost:8000/widget.js> |
| `storia` | the component catalogue | <http://localhost:8000/storia> |
| `database` | PostgreSQL 18 | `castor db:psql` |
| `mailer` | Mailpit, which catches every email | <http://localhost:8025> |
| `authelia` | the identity provider | <https://authelia.localhost:9443> |
| (in `php`) | a site being reviewed, to try the widget on | <http://staging.localhost:8001> |

A port already taken? Change it with `HTTP_PORT`, `VITE_PORT` or `MAILPIT_PORT` before `castor start`. The port of the widget's site, 8001, is the one the development data declare: it does not change. Your other local settings go in `.env.local`.

### Everyday commands

`castor list` shows every task. The most useful ones:

```bash
# The stack
castor start                    # start (alias: up)
castor stop                     # stop
castor logs messenger           # follow the logs of a service
castor docker:restart vite      # restart a service, or all of them without argument
castor docker:build             # rebuild the PHP image
castor docker:down --volumes    # remove everything, database included

# Inside the container
castor console debug:router     # bin/console
castor composer require foo/bar
castor npm install foo
castor sh                       # a shell

# Database
castor db:migration             # generate a migration after changing the entities
castor db:migrate               # apply it (castor start does too)
castor db:fixtures              # reset the demo data
castor db:psql                  # a psql console

# Quality
castor qa                       # everything the CI runs, to pass before every PR
castor test                     # the tests, in parallel
castor test --filter=IssueListTest   # a single test
castor cs                       # fix the coding style (--dry-run to only check)
castor phpstan                  # static analysis, level 8
castor qa:storia --update       # update the component snapshots

# Assets
castor assets:build             # production build
castor assets:clean             # back to the Vite server
```

### Good to know

- **Every schema change goes through a migration**: change the entities, then run `castor db:migration` and `castor db:migrate`. Never edit a migration once it has been merged.
- **PHP code reloads by itself**, except in the `messenger` worker, which loads it once and for all. After changing a handler, a notification or a scheduled task, run `castor workers:restart`, or leave `castor workers:watch` running to do it automatically.
- **To debug**: `var/log/dev.log`, the profiler at <http://localhost:8000/_profiler>, and Xdebug with `XDEBUG_MODE=debug castor start` (port 9003).
- **To try the GitLab integration without GitLab**: link a repository in a project's settings to get its secret, then replay a webhook:

  ```bash
  curl -X POST http://localhost:8000/webhook/gitlab \
       -H "X-Gitlab-Event: Push Hook" -H "X-Gitlab-Token: <repository secret>" \
       -H "Content-Type: application/json" -d @tests/Forge/Fixture/gitlab_push.json
  ```

  With `FORGE_GITLAB_HOOK_SECRET` set in `.env.local`, the same request with that secret as `X-Gitlab-Token` is taken as a delivery of the instance hook.

## License

Cairn is released under the [MIT License](LICENSE).
