<?php

declare(strict_types=1);

use Laminas\ServiceManager\ServiceManager;

/*
 * Builds the PSR-11 container. Both entry points, public/index.php and bin/console,
 * start here.
 */
$config = require __DIR__ . '/config.php';

$dependencies = $config['dependencies'];
$dependencies['services']['config'] = $config;

return new ServiceManager($dependencies);
