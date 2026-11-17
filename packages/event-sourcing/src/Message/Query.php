<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Message;

/**
 * Marker for messages that ask for information without changing state.
 *
 * A query is handled by exactly one handler, which returns the answer synchronously.
 */
interface Query {}
