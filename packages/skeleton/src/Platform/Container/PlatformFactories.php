<?php

declare(strict_types=1);

namespace App\Platform\Container;

use Acta\EventSourcing\SystemClock;
use Acta\Http\Trace\CorrelationMiddleware;
use App\Platform\Log\StreamLogger;
use App\Platform\Settings;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds what everything else relies on: the settings, the clock and the logger.
 */
final class PlatformFactories
{
    public static function settings(ContainerInterface $container): Settings
    {
        $config = $container->get('config');

        return new Settings(is_array($config) ? $config : throw new LogicException('The "config" service must be the merged configuration.'));
    }

    /**
     * The only source of the current time, so that tests can freeze it.
     */
    public static function clock(): ClockInterface
    {
        return new SystemClock();
    }

    public static function logger(ContainerInterface $container): LoggerInterface
    {
        $settings = Settings::of($container);

        return new StreamLogger(
            Resolve::from($container, ClockInterface::class),
            $settings->string('acta.log.stream', 'php://stderr'),
            $settings->string('acta.log.level', 'info'),
        );
    }

    public static function correlation(): CorrelationMiddleware
    {
        return new CorrelationMiddleware();
    }
}
