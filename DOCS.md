# Laravel inspect

See inside a Laravel app on lerd: routes, middleware, models, policies, views, commands, config, container bindings, database schema, migrations, scheduled tasks, events and listeners, broadcast channels, queues, failed jobs, storage and cache. It boots the app through `lerd php`, so it reads the real, registered state, including what packages register.

It works on Laravel 11 and newer.

## The Laravel tab

Sites that use a database get a **Laravel** tab: lerd can limit a plugin tab that way, not by framework, so a Laravel app lerd sees no database for gets none, and a site with a database that is not a Laravel app gets one that says so instead of failing.

Its sections sit in a sidebar, grouped, each with its count:

- **Overview**: counts that open their section, and `artisan about` (environment, caches, drivers, storage links, and the sections packages such as Sentry add).
- **HTTP**
  - **Routes**: method, URI, name, action and middleware.
  - **Middleware**: the global stack, the groups (pick one under **Group**) and the aliases, filterable by kind.
  - **Policies**: each model's policy with its abilities, and the gates.
  - **Channels**: broadcast channels and what authorizes them.
- **Data**
  - **Models**: every Eloquent model under `app/`. Click one for its attributes, relations, observers and row count; click a relation, or a model under **Referenced by**, to go to it; **Table** opens its table.
  - **Database**: tables with their model, size and estimated rows. Click one for columns, indexes, foreign keys and the exact row count; **Model** goes to its model.
  - **Migrations**: pending first, then the newest, each with buttons to copy its name and open its file.
- **Code**
  - **Views**: every Blade view by the name `view()` takes, `namespace::name` for namespaced ones, with its file. Under **App**, a package view shows only where the app overrides it in `resources/views/vendor`.
  - **Commands**: every Artisan command with its usage, description and class.
  - **Events** and **Listeners**: each event with its listeners, and each listener with its events and whether it is queued.
  - **Bindings**: what the container resolves each bound interface or key to, and whether it is a singleton.
  - **Schedule**: tasks with cron, next run, flags, and a button to open the command, job or closure each runs.
  - **Config**: the resolved config as a tree: each file, then each part of the key, folds open; a filter opens what matches. Leaves copy their whole key. Credentials show as dots.
- **Runtime**
  - **Queues**: jobs waiting on the queues the app uses: pending, delayed, reserved and total.
  - **Failed jobs**: the latest, with job class, queue and exception.
  - **Cache**: the project's cache stores with how much each holds, **Clear** for each after confirming; looking up a key, and **Forget** for it; and Laravel's config, route, event and view caches, with **Clear** for each after confirming.
  - **Storage**: the project's disks. Browse one a folder at a time, 500 entries a page with **Load more**; the filter searches the whole folder on the server (any part of the name on a local disk, the start of it on S3). Click a file for a preview, with **Open in viewer** for text. **Delete** removes a file after confirming. The keyboard works it too: ↑ and ↓ (or k and j) move, Enter or → opens, Backspace or ← goes up a folder and selects the one you left, Delete deletes, / jumps to the filter and Esc back to the list.

The tab loads every section at once when it opens, so moving between them is instant; **Reload** fetches the current one again, with a skeleton while it loads. The browser's Back and Forward step through it. Below 800px the sidebar becomes a select.

The filter takes several terms separated by `|`, matching any of them, such as `Order|Invoice`; so do the `filter` arguments of the CLI and MCP tools.

Routes, Middleware, Models, Policies, Views, Commands, Bindings, Events and Listeners have **App**, **Vendor** and **All**, each with its count, chosen per section: the app's own rows, the packages' rows, or both with the app's first. Under **Vendor** and **All**, a **source** column at the end says which composer package each row comes from, or **app** for the app's own. The sidebar's count is always the app's own.

Class names show their short name, and the full one on hover. Beside each is a button that copies it (a closure copies as `path:line`), and for the app's own code, when an editor is picked in lerd's settings, one that opens it there.

Clearing a Redis cache store runs FLUSHDB, which empties the store's whole Redis database. The confirmation says what else the app keeps there, such as a queue or sessions.

## CLI

Run inside a linked project. Add `--json` for raw JSON.

- `lerd laravel-inspect:overview`
- `lerd laravel-inspect:routes [filter] [--vendor]`
- `lerd laravel-inspect:middleware [filter] [--vendor]`
- `lerd laravel-inspect:policies [filter] [--vendor]`
- `lerd laravel-inspect:channels [filter]`
- `lerd laravel-inspect:models [Model] [--counts] [--vendor] [--references]`
- `lerd laravel-inspect:db-tables [filter] [--counts]`
- `lerd laravel-inspect:db-table <table>`
- `lerd laravel-inspect:migrations`
- `lerd laravel-inspect:views [filter] [--vendor]`
- `lerd laravel-inspect:commands [filter] [--vendor]`
- `lerd laravel-inspect:events [filter] [--vendor]`
- `lerd laravel-inspect:listeners [filter] [--vendor]`
- `lerd laravel-inspect:bindings [filter] [--vendor]`
- `lerd laravel-inspect:schedule`
- `lerd laravel-inspect:config [filter] [--key=mail]`
- `lerd laravel-inspect:queues [filter]`
- `lerd laravel-inspect:failed-jobs [limit]`
- `lerd laravel-inspect:storage-list <disk> [path] [--search=name] [--cursor=next]`
- `lerd laravel-inspect:storage-read <disk> <path>`
- `lerd laravel-inspect:cache-get <store> <key>`
- `lerd laravel-inspect:open <Class[@method]>` opens it in lerd's editor

## MCP and AI agents

`lerd mcp` exposes the read-only ones as tools: `laravel-inspect_overview`, `_routes`, `_middleware`, `_policies`, `_channels`, `_models`, `_db-tables`, `_db-table`, `_migrations`, `_views`, `_commands`, `_events`, `_listeners`, `_bindings`, `_schedule`, `_config`, `_queues`, `_failed-jobs`, `_storage-list`, `_storage-read`, `_cache` and `_cache-get`. Each takes the site name.

Deleting files and clearing caches are deliberately not tools: they stay buttons in the dashboard, so an agent tells you what to clear rather than doing it.

`SKILL.md` tells an agent which tool answers which question. lerd writes it beside its own skill with `lerd mcp:inject` in a project, or `lerd mcp:enable-global` for all of them; run it again after installing or updating the plugin.

## Credentials

The config is shown with credentials masked: a key whose last part ends in `key`, `secret`, `password`, `token`, `dsn` and the like, a password in a URL such as `redis://user:pass@host`, in a query string or in a DSN, and any value equal to a secret environment variable, such as one the project's `env_provider` supplies. It is a pattern match, so a secret under an unusual name in an unusual form can still show. Disks and cache stores show only where data goes (driver, root, bucket, connection), never their keys. `php/selftest.php` checks this; run it from a Laravel app's folder with `lerd php ~/.local/share/lerd/plugins/laravel-inspect/php/selftest.php`.

## Speed

When the tab opens, the overview loads on its own and every other section comes in one request, answered from one boot of the app. Routes, models, model references, events, listeners, views, commands, policies, bindings, channels and middleware are kept in an index in the plugin's data folder, and answered from it until a file under `app/`, `routes/`, `config/`, `bootstrap/`, `database/` or `resources/views/`, `composer.lock` or `.env` changes. **Reload** skips the index, for changes it cannot see, such as in a symlinked local package. The config, queues, cache, storage, migrations and failed jobs are always read live.

## Not here, because lerd already has it

- Logs: the site's **Logs** tab, or the `logs` MCP tool.
- Browsing and querying the database: the **Database** tab of lerd's db-browser plugin.
- Health checks, `composer audit` and env checks: `lerd doctor` / `diag site_doctor`.
- Per-request queries, N+1, exceptions: the **Debug** lenses and the `request` MCP tool.

## Settings

It needs `python3` on the host.

A fresh Laravel app configures many disks, cache stores and queue connections as examples, so the tab shows only the defaults and what the app uses, unless the project picks its own in `.lerd.yaml` (or a personal `.lerd.local.yaml`, which wins):

```yaml
laravel_inspect:
  disks: [local, public, s3]
  cache_stores: [redis, file]
  queues: [health, redis:ingest]
```

Reading these needs PyYAML (`python3 -c "import yaml"`); without it they are ignored and only the defaults show.
