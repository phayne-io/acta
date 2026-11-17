<?php

declare(strict_types=1);

use Laminas\ConfigAggregator\ConfigAggregator;
use Laminas\ConfigAggregator\PhpFileProvider;

/*
 * The configuration of the application: what each package and each context provides,
 * then the files of config/autoload, where later ones win.
 *
 *     *.global.php   committed, reads the environment
 *     *.local.php    not committed, overrides for one machine
 */
return new ConfigAggregator([
    Laminas\Diactoros\ConfigProvider::class,
    Mezzio\ConfigProvider::class,
    Mezzio\Router\ConfigProvider::class,
    Mezzio\Router\FastRouteRouter\ConfigProvider::class,

    // What wires the Acta libraries together.
    App\Platform\ConfigProvider::class,

    // The business contexts. `bin/console make:context` adds new ones here.
    App\Banking\ConfigProvider::class,
    // acta:contexts

    new PhpFileProvider(__DIR__ . '/autoload/{{,*.}global,{,*.}local}.php'),
])->getMergedConfig();
