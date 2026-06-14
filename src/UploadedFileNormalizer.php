<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi;

use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * UploadedFileNormalizer.
 *
 * Converts PHP's `$_FILES` superglobal into a tree of PSR-7
 * UploadedFileInterface instances. Handles the single-file shape, the flat
 * `name[]` array shape, and the deeply nested `name[a][b][]` shape — where PHP
 * transposes the field keys (`name`/`type`/`tmp_name`/`error`/`size`) across
 * the array structure. This transposition is the one historically error-prone
 * corner of request capture, so it is isolated here with its own tests.
 *
 * The returned tree mirrors the input keys; leaves are UploadedFileInterface
 * and branches are nested arrays, matching what
 * ServerRequestInterface::withUploadedFiles() expects. The `mixed` value type
 * is unavoidable: the tree is recursive to an arbitrary depth.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class UploadedFileNormalizer
{
    /**
     * Normalize a `$_FILES`-shaped array into a tree of uploaded files.
     *
     * @param array<array-key, mixed> $files The `$_FILES` superglobal (or an equivalent)
     * @param UploadedFileFactoryInterface $factory Creates PSR-7 uploaded files
     * @param StreamFactoryInterface $streams Opens the uploaded temp files as streams
     * @return array<array-key, UploadedFileInterface|array<array-key, mixed>>
     */
    public function normalize(
        array $files,
        UploadedFileFactoryInterface $factory,
        StreamFactoryInterface $streams,
    ): array {
        $normalized = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            if (array_key_exists('tmp_name', $value)) {
                $normalized[$key] = $this->createFromSpec($value, $factory, $streams);
                continue;
            }

            $normalized[$key] = $this->normalize($value, $factory, $streams);
        }

        return $normalized;
    }

    /**
     * Turn a single `$_FILES` entry into a file or a nested tree of files.
     *
     * @param array<array-key, mixed> $spec An entry carrying a `tmp_name` key
     * @return UploadedFileInterface|array<array-key, mixed>
     */
    private function createFromSpec(
        array $spec,
        UploadedFileFactoryInterface $factory,
        StreamFactoryInterface $streams,
    ): UploadedFileInterface|array {
        $tmpName = $spec['tmp_name'] ?? null;

        if (is_array($tmpName)) {
            return $this->normalizeNestedSpec($spec, $tmpName, $factory, $streams);
        }

        return $this->createFile($spec, $factory, $streams);
    }

    /**
     * Transpose a nested file spec, where each field is itself an array.
     *
     * @param array<array-key, mixed> $spec The transposed spec (fields hold arrays)
     * @param array<array-key, mixed> $tmpNames The `tmp_name` sub-array, used for keys
     * @return array<array-key, mixed>
     */
    private function normalizeNestedSpec(
        array $spec,
        array $tmpNames,
        UploadedFileFactoryInterface $factory,
        StreamFactoryInterface $streams,
    ): array {
        $result = [];

        foreach (array_keys($tmpNames) as $key) {
            $result[$key] = $this->createFromSpec(
                [
                    'tmp_name' => $this->index($spec, 'tmp_name', $key),
                    'size' => $this->index($spec, 'size', $key),
                    'error' => $this->index($spec, 'error', $key),
                    'name' => $this->index($spec, 'name', $key),
                    'type' => $this->index($spec, 'type', $key),
                ],
                $factory,
                $streams,
            );
        }

        return $result;
    }

    /**
     * Build one PSR-7 uploaded file from a flat spec.
     *
     * On a non-OK upload error, or when the temp file is missing/unreadable, an
     * empty stream is substituted — the file is unusable either way. The
     * is_readable() guard is checked first so a vanished temp file never emits an
     * fopen() warning; the try/catch remains as a TOCTOU safety net.
     *
     * @param array<array-key, mixed> $spec A flat spec (scalar fields)
     */
    private function createFile(
        array $spec,
        UploadedFileFactoryInterface $factory,
        StreamFactoryInterface $streams,
    ): UploadedFileInterface {
        $errorRaw = $spec['error'] ?? null;
        $error = is_int($errorRaw) ? $errorRaw : UPLOAD_ERR_OK;

        $sizeRaw = $spec['size'] ?? null;
        $size = is_int($sizeRaw) ? $sizeRaw : null;

        $nameRaw = $spec['name'] ?? null;
        $name = is_string($nameRaw) ? $nameRaw : null;

        $typeRaw = $spec['type'] ?? null;
        $type = is_string($typeRaw) ? $typeRaw : null;

        $tmpNameRaw = $spec['tmp_name'] ?? null;
        $tmpName = is_string($tmpNameRaw) ? $tmpNameRaw : '';

        if ($error === UPLOAD_ERR_OK && $tmpName !== '' && is_readable($tmpName)) {
            try {
                $stream = $streams->createStreamFromFile($tmpName);
            } catch (RuntimeException) {
                $stream = $streams->createStream('');
            }
        } else {
            $stream = $streams->createStream('');
        }

        return $factory->createUploadedFile($stream, $size, $error, $name, $type);
    }

    /**
     * Safely read `$spec[$field][$key]`, returning null when absent.
     *
     * @param array<array-key, mixed> $spec
     */
    private function index(array $spec, string $field, int|string $key): mixed
    {
        $bucket = $spec[$field] ?? null;

        if (is_array($bucket) && array_key_exists($key, $bucket)) {
            return $bucket[$key];
        }

        return null;
    }
}
