<?php

declare(strict_types=1);

use Mezzio\Application;

/*
 * HTTP entry point.
 */

// Lets the PHP built-in server (`composer serve`) serve the files of public/ itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))) {
    return false;
}

chdir(dirname(__DIR__));

require 'vendor/autoload.php';

(static function (): void {
    $container = require 'config/container.php';

    $app = $container->get(Application::class);

    (require 'config/pipeline.php')($app);
    (require 'config/routes.php')($app);

    $app->run();
})();
