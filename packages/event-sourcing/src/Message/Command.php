<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Message;

/**
 * Marker for messages that ask the system to change state.
 *
 * A command is named in the imperative, is handled by exactly one handler and returns
 * nothing. Implement it on a `final readonly` class: the library never requires a base
 * class, a payload array or a factory.
 */
interface Command {}
