<?php

declare(strict_types=1);

namespace Acta\Http\Validation;

/**
 * What to do with a response that does not match the contract.
 */
enum ResponseValidationMode
{
    /** Fail with a server error: for development and tests. */
    case Fail;

    /** Send the response anyway and log a warning: for production. */
    case Log;
}
