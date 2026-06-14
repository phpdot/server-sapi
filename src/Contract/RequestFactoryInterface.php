<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * RequestFactoryInterface.
 *
 * Captures a PSR-7 server request from the active request environment. This is
 * the package's own contract (distinct from PSR-17's RequestFactoryInterface,
 * which builds outgoing client requests) and the swap point the SapiServer uses.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
interface RequestFactoryInterface
{
    /**
     * Build a server request from PHP's superglobals.
     */
    public function fromGlobals(): ServerRequestInterface;
}
