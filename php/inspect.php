<?php

/*
 * Boots the Laravel app in the current folder once and prints one section of it
 * as JSON. Called by bin/inspect as: php inspect.php <section> '<json args>'.
 */

declare(strict_types=1);

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// php/selftest.php loads this file for its functions and runs no section.
$section = defined('INSPECT_LIBRARY') ? '' : ($argv[1] ?? '');
$args = json_decode($argv[2] ?? '{}', true) ?: [];

if (! is_file('artisan') || ! is_file('vendor/autoload.php')) {
    fail('Not a Laravel app with installed dependencies: ' . getcwd());
}

require 'vendor/autoload.php';

// Finding a class's file needs only the autoloader, not the booted app.
if ($section === 'locate') {
    out(locate((string) ($args['target'] ?? '')));
    exit;
}

$app = require 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$sections = [
    'overview' => 'overview',
    'routes' => 'routes',
    'models' => 'models',
    'model_references' => 'modelReferences',
    'db_tables' => 'dbTables',
    'db_table' => 'dbTable',
    'migrations' => 'migrations',
    'schedule' => 'schedule',
    'events' => 'events',
    'listeners' => 'listeners',
    'views' => 'views',
    'commands' => 'commands',
    'config' => 'configValues',
    'policies' => 'policies',
    'bindings' => 'bindings',
    'queues' => 'queues',
    'channels' => 'channels',
    'middleware' => 'middleware',
    'failed_jobs' => 'failedJobs',
    'storage_list' => 'storageList',
    'storage_read' => 'storageRead',
    'storage_delete' => 'storageDelete',
    'cache' => 'cacheOverview',
    'cache_get' => 'cacheGet',
    'cache_forget' => 'cacheForget',
    'cache_clear' => 'cacheClear',
    'framework_clear' => 'frameworkClear',
    'batch' => 'batch',
];

if (! defined('INSPECT_LIBRARY')) {
    if (! isset($sections[$section])) {
        fail('Unknown section: ' . $section);
    }
    try {
        out($sections[$section]($args));
    } catch (Throwable $e) {
        fail(get_class($e) . ': ' . $e->getMessage());
    }
}

function out(mixed $data): void
{
    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
    ), "\n";
}

function fail(string $message): never
{
    // Inside a batch one section failing must not take the others with it.
    if ($GLOBALS['batching'] ?? false) {
        throw new RuntimeException($message);
    }
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Several sections from one boot of the app: [[section, args], ...] in, [{ok: data} or {error: why}, ...] out.
 * Only sections that read: deleting and clearing stay behind their own endpoints and the dashboard's confirmation.
 */
function batch(array $args): array
{
    // A list in the function, not a const: a const at the bottom of this file is not defined yet when a section runs.
    $batchable = ['overview', 'routes', 'models', 'model_references', 'db_tables', 'db_table', 'migrations', 'schedule',
        'events', 'listeners', 'views', 'commands', 'config', 'policies', 'bindings', 'queues', 'channels', 'middleware',
        'failed_jobs', 'cache'];
    $GLOBALS['batching'] = true;
    $results = [];
    foreach ((array) ($args['items'] ?? []) as $item) {
        [$section, $sectionArgs] = array_pad((array) $item, 2, []);
        try {
            if (! in_array($section, $batchable, true)) {
                throw new RuntimeException('Not a section a batch may run: ' . $section);
            }
            $function = $GLOBALS['sections'][$section];
            $results[] = ['ok' => $function((array) $sectionArgs)];
        } catch (Throwable $e) {
            $results[] = ['error' => $e->getMessage()];
        }
    }

    return $results;
}

/** A filter matches when any of its |-separated parts is in the haystack, ignoring case; an empty one always does. */
function matches(string $haystack, ?string $filter): bool
{
    $parts = array_filter(array_map('trim', explode('|', (string) $filter)), 'strlen');
    foreach ($parts as $part) {
        if (stripos($haystack, $part) !== false) {
            return true;
        }
    }

    return $parts === [];
}

/**
 * The file and line behind "Class", "Class@method", "Class::method" or "Closure at path:line".
 *
 * @return array{path: string, line: int}
 */
function locate(string $target): array
{
    // A project file, such as a Blade view, opens at its first line. The app is not booted here,
    // so the project root is the working folder.
    if (str_ends_with($target, '.php') && ($file = realpath($target)) !== false && str_starts_with($file, getcwd() . '/')) {
        return ['path' => $file, 'line' => 1];
    }
    if (preg_match('/^Closure at (.+):(\d+)$/', $target, $m)) {
        // Only files of the project, which is where a closure's place points; lerd's adapter is not opened.
        $path = realpath($m[1]);
        if ($path === false || ! str_starts_with($path, getcwd() . '/')) {
            fail('No file in the project at ' . $m[1]);
        }

        return ['path' => $path, 'line' => (int) $m[2]];
    }

    [$class, $method] = array_pad(preg_split('/@|::/', $target, 2), 2, null);
    if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
        fail('No class ' . $class);
    }
    $ref = new ReflectionClass($class);
    if ($method === null && $ref->hasMethod('__invoke')) {
        $method = '__invoke';
    }
    $line = $method !== null && $ref->hasMethod($method) ? $ref->getMethod($method)->getStartLine() : $ref->getStartLine();

    return ['path' => $ref->getFileName() ?: fail('No file for ' . $class), 'line' => (int) $line];
}

/**
 * The disks or cache stores an app configures, with only the keys that say where data goes:
 * a disk's key and secret, or any other credential, never leave the app.
 */
function configured(string $key, string $defaultKey, ?array $chosen): array
{
    $safe = ['driver', 'root', 'bucket', 'region', 'endpoint', 'url', 'visibility', 'connection', 'lock_connection', 'table', 'path', 'prefix'];
    $rows = [];
    foreach ((array) config($key, []) as $name => $settings) {
        // shown: the ones picked in .lerd.yaml, or the default alone, since a fresh app lists many examples.
        $default = $name === config($defaultKey);
        $row = ['name' => $name, 'default' => $default, 'shown' => $chosen === null ? $default : in_array($name, $chosen, true)];
        foreach ($safe as $field) {
            if (isset($settings[$field]) && is_scalar($settings[$field]) && $settings[$field] !== '') {
                // Paths read from the project, not the container's absolute path.
                $row[$field] = is_string($settings[$field]) ? str_replace(base_path() . '/', '', $settings[$field]) : $settings[$field];
            }
        }
        $rows[] = $row;
    }

    return $rows;
}

function overview(array $args): array
{
    // Loading the console application runs what registers commands and their schedules, such as a
    // provider's callback once Artisan starts, so the count matches schedule:list.
    Artisan::all();
    $scheduled = count(app(Schedule::class)->events());
    Artisan::call('about', ['--json' => true]);
    $about = json_decode(Artisan::output(), true) ?: [];
    // about's JSON keys a link by its absolute path in lower case; read the links again, relative and as written.
    if (isset($about['storage'])) {
        $about['storage'] = [];
        foreach ((array) config('filesystems.links', [public_path('storage') => storage_path('app/public')]) as $link => $target) {
            $about['storage'][str_replace(base_path() . '/', '', $link)] = is_link($link) || file_exists($link);
        }
    }

    $pending = array_filter(migrations([]), fn ($m) => $m['status'] === 'pending');

    $failed = null;
    try {
        $failer = app('queue.failer');
        $failed = method_exists($failer, 'count') ? $failer->count() : count($failer->all());
    } catch (Throwable) {
        // No failed_jobs table yet.
    }

    // The app's own namespaces, so a page can tell its classes from vendor's.
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true) ?: [];
    $namespaces = array_keys(($composer['autoload']['psr-4'] ?? []) + ($composer['autoload-dev']['psr-4'] ?? []));

    return [
        'namespaces' => $namespaces,
        'about' => $about,
        'disks' => configured('filesystems.disks', 'filesystems.default', $args['disks'] ?? null),
        'cache_stores' => configured('cache.stores', 'cache.default', $args['cache_stores'] ?? null),
        'counts' => [
            // The app's own, as the Routes tab and the routes tool count them without vendor.
            'routes' => count(routes([])),
            'models' => count(modelClasses()),
            'pending_migrations' => count($pending),
            'failed_jobs' => $failed,
            'scheduled_tasks' => $scheduled,
        ],
    ];
}

function routes(array $args): array
{
    $rows = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uses = $route->getAction('uses');
        $controller = is_string($uses) ? strtok($uses, '@') : null;
        $file = match (true) {
            $uses instanceof Closure => (new ReflectionFunction($uses))->getFileName(),
            $controller !== null && class_exists($controller) => (new ReflectionClass($controller))->getFileName(),
            default => false,
        };
        if (empty($args['vendor']) && fromVendor($file)) {
            continue;
        }
        $row = [
            'method' => implode('|', array_diff($route->methods(), ['HEAD'])),
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'action' => $route->getAction('uses') instanceof Closure
                ? listenerName($route->getAction('uses'))
                : ltrim($route->getActionName(), '\\'),
            'middleware' => implode(', ', $route->gatherMiddleware()),
            'domain' => $route->getDomain(),
        ];
        if (matches(implode(' ', $row), $args['filter'] ?? null)) {
            $rows[] = [fromVendor($file), $row + ['source' => sourceOf($file)]];
        }
    }
    // The app's routes first, then vendor's, each in the order they were registered (usort is stable).
    usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);

    return array_column($rows, 1);
}

/**
 * Who a file belongs to: null for the app, the composer package for a file in vendor/, lerd for its own
 * debug adapter, and "external" for anything else outside the project.
 */
function sourceOf(string|false $file): ?string
{
    if ($file === false || ! fromVendor($file)) {
        return null;
    }
    if (preg_match('#/vendor/([^/]+/[^/]+)/#', str_replace(base_path(), '', $file), $m)) {
        return $m[1];
    }

    return str_contains($file, '/lerd/') ? 'lerd' : 'external';
}

/** Whether a file is someone else's: in vendor/, or outside the project, such as lerd's own debug adapter. */
function fromVendor(string|false $file): bool
{
    return $file !== false
        && (str_starts_with($file, base_path('vendor') . DIRECTORY_SEPARATOR) || ! str_starts_with($file, base_path() . DIRECTORY_SEPARATOR));
}

/** @return list<class-string<Model>> */
function modelClasses(bool $vendor = false): array
{
    // Models live anywhere under app/, such as app/Domains/*/Models.
    $roots = [[app_path(), app()->getNamespace()]];
    if ($vendor) {
        // ponytail: reads every PSR-4 file of every package; a classmap lookup if it gets slow.
        foreach (require base_path('vendor/composer/autoload_psr4.php') as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                if (fromVendor($dir) && is_dir($dir)) {
                    $roots[] = [$dir, $prefix];
                }
            }
        }
    }

    $classes = [];
    foreach ($roots as [$root, $prefix]) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            // Only load files that could hold a model.
            $contents = $file->getExtension() === 'php' ? file_get_contents($file->getPathname()) : '';
            if (! str_contains($contents, ' extends ') || ($root !== app_path() && ! str_contains($contents, 'Eloquent'))) {
                continue;
            }
            $class = $prefix . str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
            try {
                if (
                    class_exists($class)
                    && is_subclass_of($class, Model::class)
                    && ! (new ReflectionClass($class))->isAbstract()
                ) {
                    $classes[] = $class;
                }
            } catch (Throwable) {
                // A package class whose parent is a dev dependency that is not installed.
            }
        }
    }
    $classes = array_unique($classes);
    // The app's models first, then vendor's, each by name.
    usort($classes, fn ($a, $b) => [fromVendor((new ReflectionClass($a))->getFileName()), $a] <=> [fromVendor((new ReflectionClass($b))->getFileName()), $b]);

    return $classes;
}

function showModel(string $model): array
{
    // A short name like User may live outside App\Models, such as App\Domain\Identity\Models.
    if (! str_contains($model, '\\')) {
        $found = array_values(array_filter(modelClasses(), fn ($class) => class_basename($class) === $model));
        $model = count($found) === 1 ? $found[0] : $model;
    }

    // model:show is the framework's own reader of attributes, relations and observers.
    Artisan::call('model:show', ['model' => $model, '--json' => true]);
    $output = Artisan::output();

    return json_decode($output, true) ?: throw new RuntimeException('Could not inspect model ' . $model . ': ' . trim($output));
}

function models(array $args): array
{
    if (! empty($args['name'])) {
        $model = showModel($args['name']);
        $model['count'] = $model['class']::query()->count();
        if (! empty($args['references'])) {
            $model['referenced_by'] = modelReferences(['name' => $model['class']] + $args);
        }

        return $model;
    }

    $rows = [];
    foreach (modelClasses(! empty($args['vendor'])) as $class) {
        if (! matches($class, $args['filter'] ?? null)) {
            continue;
        }
        try {
            $model = showModel($class);
            $row = [
                'class' => $class,
                'source' => sourceOf((new ReflectionClass($class))->getFileName()),
                'table' => $model['table'],
                'connection' => $model['database'],
                'relations' => implode(', ', array_column($model['relations'], 'name')),
            ];
            if (! empty($args['counts'])) {
                $row['count'] = $class::query()->count();
            }
        } catch (Throwable $e) {
            $row = ['class' => $class, 'error' => $e->getMessage()];
        }
        $rows[] = $row;
    }

    return $rows;
}

/** Relations on other models that point at this one. */
function modelReferences(array $args): array
{
    $target = ltrim(showModel($args['name'] ?? fail('Pass the model name.'))['class'], '\\');
    $rows = [];
    foreach (modelClasses(! empty($args['vendor'])) as $class) {
        try {
            $relations = showModel($class)['relations'];
        } catch (Throwable) {
            continue;
        }
        foreach ($relations as $relation) {
            if (ltrim((string) $relation['related'], '\\') === $target) {
                $rows[] = ['model' => $class, 'relation' => $relation['name'], 'type' => $relation['type']];
            }
        }
    }

    return $rows;
}

function dbTables(array $args): array
{
    $connection = DB::connection($args['connection'] ?? null);
    $estimates = empty($args['counts']) ? [] : rowEstimates($connection);
    $schema = Schema::connection($connection->getName());
    // Since Laravel 12 getTables() lists every database on the server, such as the test ones.
    $current = method_exists($schema, 'getCurrentSchemaName') ? $schema->getCurrentSchemaName() : null;
    $rows = [];
    foreach ($schema->getTables($current) as $table) {
        if (! matches($table['name'], $args['filter'] ?? null)) {
            continue;
        }
        $row = ['name' => $table['name'], 'size' => $table['size'] ?? null, 'comment' => $table['comment'] ?? null];
        if (! empty($args['counts'])) {
            $row['rows'] = $estimates[$table['name']] ?? $connection->table($table['name'])->count();
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Row counts from the database's own statistics, which cost nothing where COUNT(*) on
 * every table takes seconds. Estimates on MySQL and PostgreSQL; SQLite counts exactly.
 *
 * @return array<string, int>
 */
function rowEstimates(Illuminate\Database\Connection $connection): array
{
    $sql = match ($connection->getDriverName()) {
        'mysql', 'mariadb' => 'select table_name as name, table_rows as estimate from information_schema.tables where table_schema = database()',
        'pgsql' => 'select relname as name, n_live_tup as estimate from pg_stat_user_tables',
        default => null,
    };

    return $sql === null ? [] : array_map('intval', array_column(array_map('get_object_vars', $connection->select($sql)), 'estimate', 'name'));
}

function dbTable(array $args): array
{
    $name = $args['name'] ?? fail('Pass the table name.');
    $schema = Schema::connection($args['connection'] ?? null);
    if (! $schema->hasTable($name)) {
        fail('No table ' . $name);
    }

    return [
        'table' => $name,
        'rows' => DB::connection($args['connection'] ?? null)->table($name)->count(),
        'columns' => $schema->getColumns($name),
        'indexes' => $schema->getIndexes($name),
        'foreign_keys' => $schema->getForeignKeys($name),
    ];
}

function migrations(array $args): array
{
    $migrator = app('migrator');
    $migrator->setConnection($args['connection'] ?? null);
    $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
    $repository = $migrator->getRepository();
    $batches = $repository->repositoryExists() ? $repository->getMigrationBatches() : [];

    $rows = [];
    foreach ($files as $name => $file) {
        $rows[] = [
            'migration' => $name,
            'path' => str_replace(base_path() . '/', '', $file),
            'status' => isset($batches[$name]) ? 'ran' : 'pending',
            'batch' => $batches[$name] ?? null,
        ];
    }

    // Pending first, then the newest.
    usort($rows, fn ($a, $b) => [$a['status'] === 'ran', $b['migration']] <=> [$b['status'] === 'ran', $a['migration']]);

    return $rows;
}

function schedule(array $args): array
{
    $commands = Artisan::all();
    $rows = [];
    foreach (app(Schedule::class)->events() as $event) {
        $command = $event instanceof CallbackEvent
            ? ($event->description ?: 'Closure')
            : str_replace([ConsoleApplication::phpBinary() . ' ', ConsoleApplication::artisanBinary()], ['', 'artisan'], (string) $event->command);
        $command = trim($command, ' \'"');
        $rows[] = [
            'command' => $command,
            'target' => scheduleTarget($event, $command, $commands),
            'expression' => $event->expression,
            'next_run' => $event->nextRunDate()->format(DATE_ATOM),
            'timezone' => (string) ($event->timezone ?? config('app.timezone')),
            'description' => $event->description,
            'without_overlapping' => $event->withoutOverlapping,
            'on_one_server' => $event->onOneServer,
            'in_background' => $event->runInBackground,
        ];
    }

    return $rows;
}

/**
 * The code a scheduled task runs, as the open button takes it: a command's class, a job's class,
 * or a closure's file and line; null when it cannot be told, such as a shell command.
 */
function scheduleTarget(object $event, string $command, array $commands): ?string
{
    if (! $event instanceof CallbackEvent) {
        $name = preg_match('/^artisan\s+(\S+)/', $command, $m) ? $m[1] : null;

        return $name !== null && isset($commands[$name]) ? get_class($commands[$name]) : null;
    }
    // Schedule::job() names the task after the job's class.
    if ($event->description && class_exists($event->description)) {
        return $event->description;
    }
    $callback = (new ReflectionProperty($event, 'callback'))->getValue($event);

    return match (true) {
        $callback instanceof Closure => listenerName($callback),
        is_object($callback) => get_class($callback),
        is_string($callback) && class_exists($callback) => $callback,
        default => null,
    };
}

function listenerName(mixed $listener): string
{
    if ($listener instanceof Closure) {
        $ref = new ReflectionFunction($listener);

        return 'Closure at ' . str_replace(base_path() . '/', '', (string) $ref->getFileName()) . ':' . $ref->getStartLine();
    }
    if (is_array($listener)) {
        return (is_object($listener[0]) ? get_class($listener[0]) : $listener[0]) . '@' . $listener[1];
    }

    return is_string($listener) ? $listener : get_debug_type($listener);
}

function listenerFile(mixed $listener): string|false
{
    try {
        if ($listener instanceof Closure) {
            return (new ReflectionFunction($listener))->getFileName();
        }
        $class = is_array($listener) ? $listener[0] : (is_string($listener) ? strtok($listener, '@') : null);

        return is_object($class) || (is_string($class) && class_exists($class)) ? (new ReflectionClass($class))->getFileName() : false;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Boots every model, which registers its observers and model events as listeners; without it, what the
 * events and listeners sections see depends on which models something else happened to touch first.
 */
function bootModels(bool $vendor): void
{
    foreach (modelClasses($vendor) as $class) {
        try {
            new $class();
        } catch (Throwable) {
            // A model that cannot be made without arguments boots when it is used.
        }
    }
}

function events(array $args): array
{
    bootModels(! empty($args['vendor']));
    $rows = [];
    foreach (app('events')->getRawListeners() as $event => $listeners) {
        // An event is the app's when its class is, or when the app listens to it.
        $ownListeners = array_filter($listeners, fn ($listener) => ! fromVendor(listenerFile($listener)));
        $own = $ownListeners || (class_exists($event) && ! fromVendor((new ReflectionClass($event))->getFileName()));
        if (empty($args['vendor'])) {
            // Without vendor: only the app's events, with only the app's listeners.
            if (! $own) {
                continue;
            }
            $listeners = $ownListeners;
        }
        $names = array_map('listenerName', array_values($listeners));
        if (matches($event . ' ' . implode(' ', $names), $args['filter'] ?? null)) {
            // A vendor event's package: its class's, or for a string event, its first listener's.
            $source = $own ? null : sourceOf(class_exists($event) ? (new ReflectionClass($event))->getFileName() : listenerFile(reset($listeners)));
            $rows[] = ['event' => $event, 'source' => $source, 'listeners' => $names, 'own' => $own];
        }
    }
    // The app's events first, then vendor's, each by name.
    usort($rows, fn ($a, $b) => [! $a['own'], $a['event']] <=> [! $b['own'], $b['event']]);

    return array_map(fn ($row) => ['event' => $row['event'], 'source' => $row['source'], 'listeners' => $row['listeners']], $rows);
}

/** Every listener with the events it handles, and whether and where it is queued. */
function listeners(array $args): array
{
    bootModels(! empty($args['vendor']));
    $rows = [];
    foreach (app('events')->getRawListeners() as $event => $listeners) {
        foreach ($listeners as $listener) {
            if (empty($args['vendor']) && fromVendor(listenerFile($listener))) {
                continue;
            }
            $name = listenerName($listener);
            if (! isset($rows[$name])) {
                $class = is_string($listener) ? strtok($listener, '@') : (is_array($listener) && is_string($listener[0]) ? $listener[0] : null);
                $queued = $class !== null && class_exists($class) && is_subclass_of($class, Illuminate\Contracts\Queue\ShouldQueue::class);
                $defaults = $queued ? (new ReflectionClass($class))->getDefaultProperties() : [];
                $rows[$name] = [
                    'vendor' => fromVendor(listenerFile($listener)),
                    'listener' => $name,
                    'source' => sourceOf(listenerFile($listener)),
                    'events' => [],
                    'queued' => $queued,
                    'queue' => $defaults['queue'] ?? null,
                    'connection' => $defaults['connection'] ?? null,
                ];
            }
            $rows[$name]['events'][] = $event;
        }
    }
    $rows = array_values(array_filter($rows, fn ($row) => matches($row['listener'] . ' ' . implode(' ', $row['events']), $args['filter'] ?? null)));
    // The app's listeners first, then vendor's, each by name.
    usort($rows, fn ($a, $b) => [$a['vendor'], $a['listener']] <=> [$b['vendor'], $b['listener']]);
    $rows = array_map(function ($row) {
        unset($row['vendor']);

        return $row;
    }, $rows);

    return $rows;
}

/**
 * Every Blade view by the name view() takes: dot notation, and namespace::name for the namespaces
 * packages and the app register. Folders are walked in the finder's own order, so where the app
 * overrides a package view in resources/views/vendor, its file wins as it does at render time.
 */
function views(array $args): array
{
    $finder = app('view')->getFinder();
    $folders = array_map(fn ($path) => [null, $path], $finder->getPaths());
    foreach ($finder->getHints() as $namespace => $paths) {
        foreach ($paths as $path) {
            $folders[] = [$namespace, $path];
        }
    }

    $rows = [];
    foreach ($folders as [$namespace, $folder]) {
        $folder = realpath($folder);
        if ($folder === false || (empty($args['vendor']) && fromVendor($folder))) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $name = ($namespace !== null ? $namespace . '::' : '') . str_replace('/', '.', substr($file->getPathname(), strlen($folder) + 1, -10));
            if (isset($rows[$name])) {
                continue;
            }
            $vendor = fromVendor($file->getPathname());
            $path = str_replace(base_path() . '/', '', $file->getPathname());
            $rows[$name] = [
                'name' => $name,
                'namespace' => $namespace,
                // The composer package a vendor view ships in; null for the app's own.
                'source' => sourceOf($file->getPathname()),
                'path' => $path,
                // A namespaced view the app keeps itself overrides the package's.
                'overrides_vendor' => $namespace !== null && ! $vendor && str_contains($file->getPathname(), '/resources/views/vendor/'),
            ];
        }
    }
    $rows = array_values(array_filter($rows, fn ($row) => matches($row['name'] . ' ' . $row['path'], $args['filter'] ?? null)));
    // The app's views first (plain names, then its own namespaces), then vendor's, each by name.
    usort($rows, fn ($a, $b) => [$a['source'] !== null, $a['namespace'] !== null, $a['name']] <=> [$b['source'] !== null, $b['namespace'] !== null, $b['name']]);

    return $rows;
}

function failedJobs(array $args): array
{
    $limit = min(max((int) ($args['limit'] ?? 50), 1), 500);
    $rows = [];
    foreach (array_slice(app('queue.failer')->all(), 0, $limit) as $job) {
        $job = (array) $job;
        $payload = json_decode($job['payload'] ?? '{}', true) ?: [];
        $rows[] = [
            'id' => $job['uuid'] ?? $job['id'] ?? null,
            'job' => $payload['displayName'] ?? null,
            'connection' => $job['connection'] ?? null,
            'queue' => $job['queue'] ?? null,
            'failed_at' => $job['failed_at'] ?? null,
            'exception' => strtok((string) ($job['exception'] ?? ''), "\n"),
        ];
    }

    return $rows;
}

/** A disk the app configures; any other name is refused before Storage builds it. */
function disk(array $args): Illuminate\Contracts\Filesystem\Filesystem
{
    $name = (string) ($args['disk'] ?? '');
    if (! array_key_exists($name, (array) config('filesystems.disks'))) {
        fail('No disk ' . $name);
    }

    return Storage::disk($name);
}

/**
 * One page of one folder of a disk, never recursive, limited where the data lives:
 * a local folder is read as names and only the page is stat'ed, S3 pages and filters by
 * prefix on the server, and any other driver's listing is read lazily up to the page.
 */
function storageList(array $args): array
{
    $disk = disk($args);
    // Flysystem's normalizer refuses ../ before any driver sees the path.
    $path = trim((new League\Flysystem\WhitespacePathNormalizer())->normalizePath((string) ($args['path'] ?? '')), '/');
    $limit = min(max((int) ($args['limit'] ?? 500), 1), 5000);
    $search = (string) ($args['search'] ?? '');
    $cursor = $args['cursor'] ?? null;

    try {
        $page = match (true) {
            $disk->getAdapter() instanceof League\Flysystem\Local\LocalFilesystemAdapter => localPage($disk, $path, $search, (int) $cursor, $limit),
            $disk instanceof Illuminate\Filesystem\AwsS3V3Adapter => s3Page($disk, $path, $search, $cursor, $limit),
            default => lazyPage($disk, $path, $search, (int) $cursor, $limit),
        };
    } catch (Throwable $e) {
        // Flysystem and the AWS SDK wrap the cause in a long message; the cURL or HTTP line says why.
        preg_match('/(cURL error \d+: [^(]+)|(AWS HTTP error: [^\n(]+)|(PathTraversalDetected[^\n]*)/', get_class($e) . ' ' . $e->getMessage(), $m);
        fail('Cannot list disk ' . $args['disk'] . ': ' . trim($m[0] ?? strtok($e->getMessage(), "\n")));
    }

    return ['disk' => $args['disk'], 'path' => $path] + $page;
}

function entryRow(string $path, bool $file, ?int $size, ?int $modified): array
{
    return [
        'name' => basename($path),
        'path' => $path,
        'type' => $file ? 'file' : 'dir',
        'size' => $size,
        'modified' => $modified ? date(DATE_ATOM, $modified) : null,
    ];
}

function localPage($disk, string $path, string $search, int $offset, int $limit): array
{
    $base = rtrim($disk->path($path), '/');
    if (! is_dir($base)) {
        fail('No folder ' . ($path ?: '/'));
    }
    $dirs = $files = [];
    foreach (scandir($base) ?: [] as $name) {
        if ($name === '.' || $name === '..' || ! matches($name, $search)) {
            continue;
        }
        is_dir($base . '/' . $name) ? $dirs[] = $name : $files[] = $name;
    }
    natcasesort($dirs);
    natcasesort($files);
    $names = array_merge(array_map(fn ($n) => [$n, false], $dirs), array_map(fn ($n) => [$n, true], $files));
    $rows = [];
    foreach (array_slice($names, $offset, $limit) as [$name, $file]) {
        $full = $base . '/' . $name;
        $rows[] = entryRow(ltrim($path . '/' . $name, '/'), $file, $file ? @filesize($full) ?: 0 : null, @filemtime($full) ?: null);
    }

    return ['entries' => $rows, 'total' => count($names), 'next' => $offset + $limit < count($names) ? $offset + $limit : null, 'search' => 'contains'];
}

function s3Page($disk, string $path, string $search, ?string $cursor, int $limit): array
{
    if (str_contains($search, '|')) {
        fail('S3 searches by one name prefix; | is not supported there.');
    }
    // The disk's own root (its "root" option) is part of every key, and is cut off again below.
    // path() drops a trailing slash, and without it S3 answers with the folder itself.
    $folder = fn (string $p) => ($p = trim($disk->path($p), '/')) === '' ? '' : $p . '/';
    $root = $folder('');
    $prefix = $folder($path);
    $result = $disk->getClient()->listObjectsV2(array_filter([
        'Bucket' => $disk->getConfig()['bucket'],
        'Prefix' => $prefix . $search,
        'Delimiter' => '/',
        'MaxKeys' => $limit,
        'ContinuationToken' => $cursor,
    ], fn ($v) => $v !== null && $v !== ''));
    $rows = [];
    foreach ($result['CommonPrefixes'] ?? [] as $dir) {
        $rows[] = entryRow(rtrim(substr($dir['Prefix'], strlen($root)), '/'), false, null, null);
    }
    foreach ($result['Contents'] ?? [] as $object) {
        if ($object['Key'] !== $prefix) {
            $rows[] = entryRow(substr($object['Key'], strlen($root)), true, (int) $object['Size'], $object['LastModified']?->getTimestamp());
        }
    }

    return ['entries' => $rows, 'next' => ($result['IsTruncated'] ?? false) ? $result['NextContinuationToken'] : null, 'search' => 'prefix'];
}

function lazyPage($disk, string $path, string $search, int $offset, int $limit): array
{
    $rows = [];
    $seen = 0;
    $next = null;
    foreach ($disk->getDriver()->listContents($path, false) as $entry) {
        if (! matches(basename($entry->path()), $search)) {
            continue;
        }
        if ($seen++ < $offset) {
            continue;
        }
        if (count($rows) === $limit) {
            $next = $offset + $limit;
            break;
        }
        $file = $entry->isFile();
        $rows[] = entryRow($entry->path(), $file, $file ? $entry->fileSize() : null, $entry->lastModified());
    }
    usort($rows, fn ($a, $b) => [$a['type'] !== 'dir', $a['name']] <=> [$b['type'] !== 'dir', $b['name']]);

    return ['entries' => $rows, 'next' => $next, 'search' => 'contains'];
}

/** A file's text, or an image as a data URL; anything else, or over 1 MB, only its size and type. */
function storageRead(array $args): array
{
    $disk = disk($args);
    $path = (string) ($args['path'] ?? fail('Pass the path.'));
    if (! $disk->fileExists($path)) {
        fail('No file ' . $path);
    }
    $size = $disk->size($path);
    $mime = $disk->mimeType($path) ?: 'application/octet-stream';
    $file = ['disk' => $args['disk'], 'path' => $path, 'size' => $size, 'mime' => $mime];
    if ($size > 1024 * 1024) {
        return $file + ['note' => 'Over 1 MB, not read.'];
    }
    $content = (string) $disk->get($path);
    if (str_starts_with($mime, 'image/')) {
        return $file + ['image' => 'data:' . $mime . ';base64,' . base64_encode($content)];
    }
    if (mb_check_encoding($content, 'UTF-8') && ! str_contains($content, "\0")) {
        return $file + ['text' => $content];
    }

    return $file + ['note' => 'Binary, not shown.'];
}

function storageDelete(array $args): array
{
    $path = (string) ($args['path'] ?? fail('Pass the path.'));

    return ['deleted' => disk($args)->delete($path), 'path' => $path];
}

/** A store the app configures; any other name is refused. */
function store(array $args): string
{
    $name = (string) ($args['store'] ?? '');
    if (! array_key_exists($name, (array) config('cache.stores'))) {
        fail('No cache store ' . $name);
    }

    return $name;
}

/**
 * The stores, with what else lives in the same Redis database: clearing a Redis store
 * runs FLUSHDB, which empties that whole database, queues and sessions in it included.
 */
function cacheOverview(array $args): array
{
    $where = function (?string $connection): ?string {
        $redis = config('database.redis.' . ($connection ?: 'default'));

        return is_array($redis) ? ($redis['url'] ?? (($redis['host'] ?? '') . ':' . ($redis['port'] ?? '') . '/' . ($redis['database'] ?? 0))) : null;
    };
    // Everything else that may keep data in Redis.
    $others = [];
    foreach ((array) config('queue.connections') as $name => $queue) {
        if (($queue['driver'] ?? null) === 'redis') {
            $others['queue ' . $name] = $where($queue['connection'] ?? null);
        }
    }
    if (config('session.driver') === 'redis') {
        $others['sessions'] = $where(config('session.connection'));
    }

    $stores = configured('cache.stores', 'cache.default', $args['cache_stores'] ?? null);
    foreach ($stores as &$store) {
        $settings = config('cache.stores.' . $store['name']);
        if ($store['shown']) {
            $store += cacheSize($store['name'], $settings);
        }
        if (($settings['driver'] ?? null) === 'redis') {
            $mine = $where($settings['connection'] ?? null);
            $shared = array_keys(array_filter($others, fn ($other) => $other === $mine));
            foreach ((array) config('cache.stores') as $name => $other) {
                if ($name !== $store['name'] && ($other['driver'] ?? null) === 'redis' && $where($other['connection'] ?? null) === $mine) {
                    $shared[] = 'cache store ' . $name;
                }
            }
            $store['shares_redis_with'] = $shared;
        }
    }

    return [
        'stores' => $stores,
        'framework' => [
            ['cache' => 'config', 'cached' => app()->configurationIsCached()],
            ['cache' => 'routes', 'cached' => app()->routesAreCached()],
            ['cache' => 'events', 'cached' => app()->eventsAreCached()],
            ['cache' => 'views', 'cached' => (bool) glob(config('view.compiled') . '/*.php')],
        ],
    ];
}

/**
 * How much a store holds: files and bytes for file, rows and bytes for database, and for
 * Redis the keys in its database (DBSIZE), since Redis keeps no memory figure per database.
 *
 * @return array{entries?: int, size?: int}
 */
function cacheSize(string $name, array $settings): array
{
    try {
        switch ($settings['driver'] ?? null) {
            case 'file':
                $entries = $size = 0;
                if (is_dir($settings['path'] ?? '')) {
                    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($settings['path'], FilesystemIterator::SKIP_DOTS)) as $file) {
                        $entries++;
                        $size += $file->getSize();
                    }
                }

                return ['entries' => $entries, 'size' => $size];
            case 'database':
                $connection = DB::connection($settings['connection'] ?? null);
                $table = $settings['table'] ?? 'cache';
                $info = collect(Schema::connection($connection->getName())->getTables())->firstWhere('name', $table);

                return ['entries' => $connection->table($table)->count(), 'size' => $info['size'] ?? null];
            case 'redis':
                return ['entries' => (int) Cache::store($name)->getStore()->connection()->command('dbsize')];
        }
    } catch (Throwable) {
        // A store whose backend is not running says nothing about its size.
    }

    return [];
}

function cacheGet(array $args): array
{
    $store = Cache::store(store($args));
    $key = (string) ($args['key'] ?? fail('Pass the key.'));
    if (! $store->has($key)) {
        return ['key' => $key, 'exists' => false];
    }
    $value = $store->get($key);

    return [
        'key' => $key,
        'exists' => true,
        'type' => get_debug_type($value),
        // ponytail: 64 KB of print_r; a paged view if bigger values matter.
        'value' => is_scalar($value) ? $value : substr(print_r($value, true), 0, 65536),
    ];
}

function cacheForget(array $args): array
{
    $key = (string) ($args['key'] ?? fail('Pass the key.'));

    return ['key' => $key, 'forgotten' => Cache::store(store($args))->forget($key)];
}

function cacheClear(array $args): array
{
    $name = store($args);
    $code = Artisan::call('cache:clear', ['store' => $name]);

    return ['store' => $name, 'cleared' => $code === 0, 'output' => trim(Artisan::output())];
}

/** Laravel's own caches: config, routes, events, views. */
function frameworkClear(array $args): array
{
    $command = match ($args['cache'] ?? '') {
        'config' => 'config:clear',
        'routes' => 'route:clear',
        'events' => 'event:clear',
        'views' => 'view:clear',
        default => fail('Pass cache: config, routes, events or views.'),
    };
    $code = Artisan::call($command);

    return ['cache' => $args['cache'], 'cleared' => $code === 0, 'output' => trim(Artisan::output())];
}

/** Sorts rows with a source the app's first (source null), then vendor's, each by the given key. */
function appFirst(array $rows, string $key): array
{
    usort($rows, fn ($a, $b) => [$a['source'] !== null, $a[$key]] <=> [$b['source'] !== null, $b[$key]]);

    return $rows;
}

function classFile(object|string $class): string|false
{
    try {
        return (new ReflectionClass($class))->getFileName();
    } catch (Throwable) {
        return false;
    }
}

/** Every Artisan command with its usage, description and class; hidden ones only with vendor. */
function commands(array $args): array
{
    $rows = [];
    foreach (Artisan::all() as $name => $command) {
        $source = sourceOf(classFile($command));
        if ((empty($args['vendor']) && $source !== null) || ($command->isHidden() && empty($args['vendor']))) {
            continue;
        }
        $row = [
            'command' => $name,
            'usage' => trim(preg_replace('/^' . preg_quote($name, '/') . '/', '', $command->getSynopsis(true))) ?: null,
            'description' => $command->getDescription() ?: null,
            'class' => get_class($command),
            'source' => $source,
        ];
        if (matches($name . ' ' . $row['description'] . ' ' . $row['class'], $args['filter'] ?? null)) {
            $rows[] = $row;
        }
    }

    return appFirst($rows, 'command');
}

/**
 * The config as the app resolved it, flattened to dot keys. A value is masked when its key reads like a
 * credential, or when it equals the value of an environment variable whose name does, such as one an
 * env_provider supplies, so secrets never leave the app.
 */
function configValues(array $args): array
{
    // A key whose last part ends in one of these words: api_key and password, not foreign_key_constraints.
    $secretKey = '/(^|_)(key|keys|secret|password|passwd|pass|token|dsn|credentials?|salt|signature|passphrase)$/i';
    // An environment variable named like one, whose value is then masked wherever the config holds it.
    $secretName = '/(key|secret|password|passwd|pass|token|dsn|credential|private|salt|signature|auth)(_|$)/i';
    $secrets = [];
    foreach (array_merge(getenv(), $_ENV, $_SERVER) as $name => $value) {
        if (is_string($name) && is_string($value) && strlen($value) >= 6 && preg_match($secretName, $name . '_')) {
            $secrets[$value] = true;
        }
    }
    $rows = [];
    $walk = function (array $values, string $prefix) use (&$walk, &$rows, $secretKey, $secrets) {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            // A list of plain values reads best as one row.
            if (is_array($value) && $value !== [] && (! array_is_list($value) || array_filter($value, 'is_array'))) {
                $walk($value, $path);
                continue;
            }
            // A flag, a number or a class name named like a credential is not one, such as supports_credentials.
            $plain = is_bool($value) || is_int($value) || is_float($value) || (is_string($value) && str_contains($value, '\\') && class_exists($value));
            $masked = (! $plain && preg_match($secretKey, (string) $key)) || (is_string($value) && isset($secrets[$value]));
            // A URL with a password in it, such as redis://user:pass@host, keeps all but the password.
            if (! $masked && is_string($value) && preg_match('#^([a-z][a-z0-9+.-]*://[^:/@\s]*:)([^@\s]+)(@.*)$#i', $value, $url)) {
                $rows[] = ['key' => $path, 'value' => $url[1] . '••••••' . $url[3], 'masked' => true];
                continue;
            }
            $shown = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value;
            $rows[] = [
                'key' => $path,
                'value' => $masked && $value !== null && $value !== '' ? '••••••' : (is_object($shown) ? get_debug_type($shown) : $shown),
                'masked' => $masked && $value !== null && $value !== '',
            ];
        }
    };
    $walk(config()->all(), '');
    if (! empty($args['key'])) {
        $rows = array_values(array_filter($rows, fn ($row) => $row['key'] === $args['key'] || str_starts_with($row['key'], $args['key'] . '.')));
    }

    return array_values(array_filter($rows, fn ($row) => matches($row['key'] . ' ' . (is_scalar($row['value']) ? $row['value'] : ''), $args['filter'] ?? null)));
}

/**
 * Each model's policy, whether registered or found by Laravel's naming, with its abilities; and the gates.
 * One list, kind telling them apart, so it counts, filters and sorts like the others.
 */
function policies(array $args): array
{
    $gate = app(Illuminate\Contracts\Auth\Access\Gate::class);
    $rows = [];
    foreach (modelClasses(! empty($args['vendor'])) as $model) {
        $policy = $gate->getPolicyFor($model);
        if ($policy === null) {
            continue;
        }
        $methods = array_map(fn ($m) => $m->getName(), array_filter(
            (new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC),
            // Abilities the app writes, on the policy or a base policy of its own, not those a vendor trait adds.
            fn ($m) => ! $m->isStatic() && ! str_starts_with($m->getName(), '__') && ! fromVendor($m->getFileName())
        ));
        $rows[] = ['kind' => 'policy', 'model' => $model, 'policy' => get_class($policy), 'abilities' => implode(', ', $methods),
            'source' => sourceOf(classFile($policy))];
    }
    foreach ($gate->abilities() as $ability => $callback) {
        $rows[] = ['kind' => 'gate', 'ability' => $ability, 'callback' => listenerName($callback),
            'source' => sourceOf($callback instanceof Closure ? (new ReflectionFunction($callback))->getFileName() : listenerFile($callback))];
    }
    $rows = array_values(array_filter($rows, fn ($row) => (! empty($args['vendor']) || $row['source'] === null)
        && matches(implode(' ', array_filter($row, 'is_string')), $args['filter'] ?? null)));
    usort($rows, fn ($a, $b) => [$a['kind'], $a['source'] !== null, $a['model'] ?? $a['ability']] <=> [$b['kind'], $b['source'] !== null, $b['model'] ?? $b['ability']]);

    return $rows;
}

/** What the container hands out for each abstract: the concrete class or closure, and whether it is shared. */
function bindings(array $args): array
{
    $rows = [];
    foreach (app()->getBindings() as $abstract => $binding) {
        $concrete = $binding['concrete'];
        // Laravel wraps a bound class name in a closure; its static variables still name the class.
        $statics = $concrete instanceof Closure ? (new ReflectionFunction($concrete))->getStaticVariables() : [];
        $concreteName = isset($statics['concrete']) && is_string($statics['concrete'])
            ? ltrim($statics['concrete'], '\\')
            : listenerName($concrete);
        $file = isset($statics['concrete']) && is_string($statics['concrete'])
            ? classFile($statics['concrete'])
            : ($concrete instanceof Closure ? (new ReflectionFunction($concrete))->getFileName() : false);
        $source = sourceOf($file);
        if (empty($args['vendor']) && $source !== null) {
            continue;
        }
        $row = ['abstract' => $abstract, 'concrete' => $concreteName === $abstract ? null : $concreteName, 'shared' => (bool) $binding['shared'], 'source' => $source];
        if (matches($abstract . ' ' . $concreteName, $args['filter'] ?? null)) {
            $rows[] = $row;
        }
    }

    return appFirst($rows, 'abstract');
}

/**
 * Jobs waiting on the queues the app uses: the default connection's default queue, the queues the app's
 * own queued classes name in $queue, the queues of failed jobs, Horizon's supervisors where installed,
 * and any the project lists under laravel_inspect.queues in .lerd.yaml ("name" or "connection:name").
 * The config's other connections are mostly Laravel's examples, so they are left out.
 */
function queues(array $args): array
{
    $default = config('queue.default');
    $names = [$default => [config("queue.connections.$default.queue") ?? 'default' => true]];
    $add = function (?string $connection, ?string $queue) use (&$names, $default) {
        if ($queue !== null && $queue !== '') {
            $names[$connection ?: $default][$queue] = true;
        }
    };
    // ponytail: reads every app file to find queued classes; an index entry if it gets slow.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        $contents = $file->getExtension() === 'php' ? file_get_contents($file->getPathname()) : '';
        if (! str_contains($contents, 'ShouldQueue') || ! preg_match('/namespace\s+([^;]+);.*?(?:class|enum)\s+(\w+)/s', $contents, $m)) {
            continue;
        }
        try {
            $class = $m[1] . '\\' . $m[2];
            if (class_exists($class) && is_subclass_of($class, Illuminate\Contracts\Queue\ShouldQueue::class)) {
                $defaults = (new ReflectionClass($class))->getDefaultProperties();
                $add($defaults['connection'] ?? null, is_string($defaults['queue'] ?? null) ? $defaults['queue'] : null);
            }
        } catch (Throwable) {
            // A class that cannot be loaded says nothing about queues.
        }
    }
    try {
        foreach (app('queue.failer')->all() as $job) {
            $add($job->connection ?? null, $job->queue ?? null);
        }
    } catch (Throwable) {
        // No failed_jobs table.
    }
    foreach ((array) config('horizon.defaults') + (array) config('horizon.environments.' . app()->environment()) as $supervisor) {
        foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
            $add($supervisor['connection'] ?? null, $queue);
        }
    }
    foreach ((array) ($args['queues'] ?? []) as $entry) {
        str_contains($entry, ':') ? $add(...explode(':', $entry, 2)) : $add(null, $entry);
    }

    $rows = [];
    foreach ($names as $connection => $queues) {
        foreach (array_keys($queues) as $queue) {
            $row = ['connection' => $connection, 'queue' => $queue, 'driver' => config("queue.connections.$connection.driver")];
            try {
                $q = Illuminate\Support\Facades\Queue::connection($connection);
                foreach (['pending' => 'pendingSize', 'delayed' => 'delayedSize', 'reserved' => 'reservedSize'] as $key => $method) {
                    $row[$key] = method_exists($q, $method) ? $q->$method($queue) : null;
                }
                $row['total'] = $q->size($queue);
            } catch (Throwable $e) {
                $row['error'] = strtok($e->getMessage(), "\n");
            }
            if (matches($connection . ' ' . $queue, $args['filter'] ?? null)) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

/** The broadcast channels the app authorizes, with the callback or class that decides. */
function channels(array $args): array
{
    $broadcaster = app(Illuminate\Contracts\Broadcasting\Factory::class)->connection();
    if (! method_exists($broadcaster, 'getChannels')) {
        return [];
    }
    $rows = [];
    foreach ($broadcaster->getChannels() as $channel => $callback) {
        $row = ['channel' => $channel, 'authorized_by' => is_string($callback) ? $callback : listenerName($callback)];
        if (matches(implode(' ', $row), $args['filter'] ?? null)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/** Global middleware, the groups such as web and api with what they run, and the aliases routes use. */
function middleware(array $args): array
{
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $router = app('router');
    $row = fn (string $kind, string $name, string $class) => [
        'kind' => $kind, 'name' => $name, 'class' => ltrim($class, '\\'), 'source' => sourceOf(classFile(strtok($class, ':'))),
    ];
    $rows = [];
    foreach ($kernel->getGlobalMiddleware() as $class) {
        $rows[] = $row('global', '', $class);
    }
    foreach ($router->getMiddlewareGroups() as $group => $classes) {
        foreach ($classes as $class) {
            $rows[] = $row('group', $group, $class);
        }
    }
    foreach ($router->getMiddleware() as $alias => $class) {
        $rows[] = $row('alias', $alias, $class);
    }
    $rows = array_values(array_filter($rows, fn ($r) => (! empty($args['vendor']) || $r['source'] === null || $r['kind'] !== 'alias')
        && matches($r['name'] . ' ' . $r['class'], $args['filter'] ?? null)));

    return $rows;
}
