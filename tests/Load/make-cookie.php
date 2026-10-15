<?php

/*
 * Prints a logged-in session cookie ("name=value") for the first admin (or --email) of the app in
 * the CURRENT DIRECTORY / given path, so run.php can fetch authenticated pages without the Livewire login.
 * Run it from the app root of the COPY you are load-testing, with the same env (DB_DATABASE, SESSION_DRIVER, ...):
 *
 *   php /path/to/tests/Load/make-cookie.php [app-root] [email]
 */

$root = realpath($argv[1] ?? getcwd());
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

$user = isset($argv[2])
    ? App\Models\User::query()->where('email', $argv[2])->firstOrFail()
    : App\Models\User::query()->where('role', 'admin')->firstOrFail();

$session = $app['session']->driver();
$session->setId(Str::random(40));
$session->start();
Auth::guard('web')->login($user);
$session->regenerateToken();
$session->save();

$name = config('session.cookie');
$key = $app['encrypter'];
$value = $key->encrypt(CookieValuePrefix::create($name, $key->getKey()).$session->getId(), false);

echo $name.'='.rawurlencode($value).PHP_EOL;
