# lerd-laravel-inspect

A [lerd](https://github.com/lerd-env/lerd) plugin that shows what is inside a Laravel app: routes, middleware, models and policies, views, Artisan commands, the resolved config, container bindings, database schema, migrations, scheduled tasks, events and listeners, broadcast channels, queues, failed jobs, storage and cache. It reads what the app has actually registered, packages included, rather than guessing from files.

![The Laravel tab's overview](screenshots/overview.png)

## Install

From lerd's dashboard, **Plugins** › **Browse**, or:

```sh
lerd plugin install https://github.com/ExeQue/lerd-laravel-inspect
```

It needs lerd 1.39 or newer, `python3` on the host, and a Laravel 11+ app. The optional `laravel_inspect:` settings in `.lerd.yaml` are read with PyYAML; without it they are ignored and only the default disk, cache store and queues show.

## What it adds to lerd

- **A site tab**, **Laravel**, on sites that use a database (lerd can limit a plugin tab that way, not by framework). Its sections sit in a grouped sidebar: Overview; HTTP (routes, middleware, policies, channels); Data (models, database, migrations); Code (views, commands, events, listeners, bindings, schedule, config); Runtime (queues, failed jobs, cache, storage). On a site that is not a Laravel app it says so.
- **MCP tools** for AI assistants, `laravel-inspect_overview`, `_routes`, `_models` and the rest, all read-only, and a skill (`SKILL.md`) that tells an assistant which tool answers which question. lerd writes the skill with `lerd mcp:inject` or `lerd mcp:enable-global`.
- **CLI commands**, `lerd laravel-inspect:routes` and the rest, run inside a linked project.
- **API endpoints** under `/api/plugins/laravel-inspect/`, loopback only, which the tab uses.

It adds no hooks, page scripts, notifications, services or frameworks.

[DOCS.md](DOCS.md) describes every section, command and tool.

![Routes with vendor rows and their source](screenshots/routes.png)

## How it works

`bin/inspect` is one Python script; every `bin/<section>` is a link to it, and the name it is called by picks the section. It runs `php/inspect.php` with `lerd php` in the site's folder, so the PHP runs in lerd's PHP container for that site, not on the host. That script boots the app once and prints the section as JSON. When the tab opens, all sections come from one such boot.

Answers that only depend on code (routes, models, events, listeners, views, commands, policies, bindings, channels, middleware) are kept in an index in the plugin's data folder until a file in the app changes.

![A model with its relations both ways](screenshots/model.png)

## What it reads

- The site's code and the state its booted app reports, through Laravel's own APIs (`artisan about`, `model:show`, the router, the container, the event dispatcher, the schedule, the migrator).
- The app's resolved config, which includes values from its `.env` and its lerd `env_provider`. Credentials are masked before they leave the app: keys named like one (`key`, `secret`, `password`, `token`, `dsn` and the like), a password in a URL such as `redis://user:pass@host`, in a query string (`?token=`) or in a DSN (`password=`), and any value equal to a secret environment variable of six or more characters. It is a pattern match, so a secret under an unusual name in an unusual form can still show. Disks and cache stores show where data goes, never their keys. `php/selftest.php` checks this; run it from a Laravel app's folder with `lerd php ~/.local/share/lerd/plugins/laravel-inspect/php/selftest.php`.
- Its database schema and row counts, its migrations table, failed jobs and queue sizes.
- Files and folders on the app's storage disks, and cache keys, when browsed in the tab or asked for by a tool.
- The project's `.lerd.yaml` and `.lerd.local.yaml`, for the optional `laravel_inspect:` settings (which disks, cache stores and queues to show).
- lerd's API, with the token lerd gives the plugin: which editor is set (`/api/editors`), and to open a file in it (`/api/open-editor`).

## What it changes

Only on the user's action in the tab, each behind a confirmation where it cannot be undone:

- **Delete** removes a file from a storage disk.
- **Clear** empties a cache store (on Redis, `FLUSHDB` of its database; the confirmation names what else lives there), **Forget** removes one cache key, and **Clear** on Laravel's config, route, event or view cache runs its `artisan …:clear`.
- The open buttons ask lerd to open a file in the editor set in lerd.

The MCP tools and CLI commands change nothing. The plugin writes only its index, in its own data folder.

## Network

The plugin sends nothing anywhere. Browsing an S3 disk lists it through the app's own S3 configuration, which talks to whatever endpoint the app is set up with (often lerd's local RustFS).

What the MCP tools answer goes to the AI assistant that asked, and from there to whichever model it runs on, as with any MCP tool. That includes file contents from `storage-read`, cache values from `cache-get`, and exception messages from `failed-jobs`, which are not masked. A disk can be rooted anywhere the app sets it to, so a disk rooted at the project can show its files, `.env` included.

![The resolved config as a tree](screenshots/config.png)

![A file on a storage disk, previewed](screenshots/storage.png)

![Cache stores and Laravel's own caches](screenshots/cache.png)

## License

MIT
