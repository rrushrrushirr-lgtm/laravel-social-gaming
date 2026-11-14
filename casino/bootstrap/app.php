<?php

/*
|--------------------------------------------------------------------------
| Recreate runtime environment after a fresh deployment
|--------------------------------------------------------------------------
|
| The installer writes casino/.env, but deploy containers are ephemeral.
| Rebuild the demo configuration from EthioDeploy's managed DATABASE_URL
| when that file is absent. Never commit database credentials to Git.
|
*/

$envPath = dirname(__DIR__) . '/.env';

if (!is_file($envPath)) {
    $databaseUrl = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '');

    if ($databaseUrl !== '') {
        $parts = parse_url($databaseUrl);

        if (is_array($parts) && !empty($parts['host']) && !empty($parts['user']) && isset($parts['pass']) && !empty($parts['path'])) {
            $domain = getenv('APP_URL') ?: 'https://laravel-social-gaming.ethiodeploy.app';
            $domain = preg_replace('#^https?://#i', '', rtrim($domain, '/'));
            $env = [
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
                'APP_URL' => 'https://' . $domain,
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $parts['host'],
                'DB_PORT' => (string) ($parts['port'] ?? 3306),
                'DB_DATABASE' => ltrim($parts['path'], '/'),
                'DB_USERNAME' => rawurldecode($parts['user']),
                'DB_PASSWORD' => rawurldecode($parts['pass']),
                'DB_PREFIX' => 'w_',
                'CACHE_DRIVER' => 'file',
                'SESSION_DRIVER' => 'file',
                'QUEUE_DRIVER' => 'sync',
                'BROADCAST_DRIVER' => 'log',
                'SESSION_LIFETIME' => '120',
                'MAIL_MAILER' => 'log',
                'MAIL_HOST' => '127.0.0.1',
                'MAIL_PORT' => '2525',
                'MAIL_USERNAME' => '',
                'MAIL_PASSWORD' => '',
                'MAIL_ENCRYPTION' => 'null',
                'MAIL_FROM_ADDRESS' => 'no-reply@' . $domain,
                'MAIL_FROM_NAME' => 'Social Gaming Demo',
                'JWT_SECRET' => bin2hex(random_bytes(32)),
                'MIN_ADD' => '100',
                'MAX_ADD' => '1000000',
                'MAX_INVITES' => '10',
                'REGISTER_NOTIFY_EMAIL' => '',
                'DEMO_KEY' => '',
            ];

            $lines = [];
            foreach ($env as $key => $value) {
                $value = str_replace(["\\\\", '"', "\r", "\n"], ["\\\\\\\\", '\\"', '', ''], (string) $value);
                $lines[] = $key . '="' . $value . '"';
            }

            @file_put_contents($envPath, implode("\n", $lines) . "\n", LOCK_EX);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Environment Variables
|--------------------------------------------------------------------------
|
| Ensure the .env file is read before the application is bootstrapped.
| Without this, the framework falls back to default config values and
| database credentials may be wrong.
|
*/

if (file_exists(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    VanguardLTE\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    VanguardLTE\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    VanguardLTE\Exceptions\Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
