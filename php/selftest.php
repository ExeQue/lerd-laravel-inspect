<?php

/*
 * Checks the logic a mistake in would leak or mislead: credential masking, the | filter and where a
 * file comes from. Run it in any Laravel app: cd my-app && lerd php <plugin>/php/selftest.php
 */

declare(strict_types=1);

define('INSPECT_LIBRARY', true);
require __DIR__ . '/inspect.php';

$checks = 0;
function check(bool $ok, string $what): void
{
    global $checks;
    $checks++;
    if (! $ok) {
        fwrite(STDERR, "FAIL: $what\n");
        exit(1);
    }
}

// The | filter: any term, ignoring case; empty parts and an empty filter match everything.
check(matches('App\Models\CaseInvoice', 'order|INVOICE'), 'a | filter matches either term, ignoring case');
check(! matches('App\Models\User', 'order|invoice'), 'a | filter misses when no term is there');
check(matches('anything', '') && matches('anything', null) && matches('anything', '|'), 'an empty filter matches');
check(matches('order', 'order|'), 'an empty part is ignored');

// Where a file comes from.
check(sourceOf(base_path('app/Models/User.php')) === null, 'the app is no source');
check(sourceOf(base_path('vendor/laravel/framework/src/Foo.php')) === 'laravel/framework', 'a vendor file is its package');
check(sourceOf('/usr/local/etc/lerd/laravel-adapter.php') === 'lerd', "lerd's adapter is lerd");
check(sourceOf('/opt/elsewhere/x.php') === 'external', 'a file outside the project is external');

// Masking: credentials never leave, harmless values named like one stay readable.
putenv('LI_SELFTEST_TOKEN=s3cr3t-from-env');
$_ENV['LI_SELFTEST_TOKEN'] = 's3cr3t-from-env';
config(['li_selftest' => [
    'api_key' => 'abcdef123456',
    'password' => 'hunter2-hunter2',
    'copied' => 's3cr3t-from-env',
    'foreign_key_constraints' => true,
    'supports_credentials' => false,
    'aliases' => ['Password' => Illuminate\Support\Facades\Password::class],
    'plain' => 'hello',
    'empty_secret' => '',
]]);
$rows = array_column(configValues(['key' => 'li_selftest']), null, 'key');
$json = json_encode($rows);
check($rows['li_selftest.api_key']['masked'], 'api_key is masked');
check($rows['li_selftest.password']['masked'], 'password is masked');
check($rows['li_selftest.copied']['masked'], 'a value equal to a secret environment variable is masked');
check(! str_contains($json, 'abcdef123456') && ! str_contains($json, 'hunter2') && ! str_contains($json, 's3cr3t-from-env'), 'no secret value is in the output');
check(! $rows['li_selftest.foreign_key_constraints']['masked'], 'foreign_key_constraints is not masked');
check(! $rows['li_selftest.supports_credentials']['masked'], 'a flag is not masked');
check(! $rows['li_selftest.aliases.Password']['masked'], 'a class name is not masked');
check($rows['li_selftest.plain']['value'] === 'hello', 'a plain value shows');

// Disks and stores show where data goes, never their keys.
config(['filesystems.disks.li_selftest' => ['driver' => 's3', 'key' => 'AKIASELFTEST', 'secret' => 'shh-selftest', 'bucket' => 'b']]);
$disk = array_column(configured('filesystems.disks', 'filesystems.default', null), null, 'name')['li_selftest'];
check(! isset($disk['key']) && ! isset($disk['secret']) && $disk['bucket'] === 'b', "a disk's key and secret are left out");

echo "ok, $checks checks\n";
