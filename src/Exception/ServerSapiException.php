<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi\Exception;

use RuntimeException;

/**
 * ServerSapiException.
 *
 * Base exception for all phpdot/server-sapi runtime errors. Catch this to
 * handle any failure originating from the package.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
class ServerSapiException extends RuntimeException {}
