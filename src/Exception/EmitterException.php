<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi\Exception;

/**
 * EmitterException.
 *
 * Thrown when a response cannot be written to the SAPI — most commonly
 * because headers (or other output) have already been sent.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class EmitterException extends ServerSapiException
{
    /**
     * Build an exception describing where output was already started.
     *
     * @param string $file The file that started output, or '' if unknown
     * @param int $line The line that started output, or 0 if unknown
     */
    public static function headersAlreadySent(string $file, int $line): self
    {
        if ($file !== '') {
            return new self(sprintf(
                'Cannot emit response: output already started in %s on line %d.',
                $file,
                $line,
            ));
        }

        return new self('Cannot emit response: output already started.');
    }
}
