<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi\Contract;

use PHPdot\Server\Sapi\Exception\EmitterException;
use Psr\Http\Message\ResponseInterface;

/**
 * EmitterInterface.
 *
 * Writes a PSR-7 response to the active SAPI: status line, headers, and body.
 * The swap point for alternate emission strategies.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
interface EmitterInterface
{
    /**
     * Emit a PSR-7 response to the SAPI.
     *
     * @param ResponseInterface $response The response to send
     * @throws EmitterException If output has already been started
     */
    public function emit(ResponseInterface $response): void;
}
