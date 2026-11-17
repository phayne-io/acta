<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

/**
 * Marker for messages that must be handled out of process.
 *
 * A bus equipped with the {@see Middleware\AsyncMiddleware} hands such messages over to
 * a {@see MessageProducer} instead of calling their handler.
 */
interface AsyncMessage {}
