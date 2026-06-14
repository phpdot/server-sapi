<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi\Tests\Unit;

use PHPdot\Http\ResponseFactory;
use PHPdot\Server\Sapi\UploadedFileNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

final class UploadedFileNormalizerTest extends TestCase
{
    private ResponseFactory $http;
    private UploadedFileNormalizer $normalizer;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->http = new ResponseFactory();
        $this->normalizer = new UploadedFileNormalizer();
        $this->tempFiles = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    #[Test]
    public function normalizesEmptyFiles(): void
    {
        self::assertSame([], $this->normalizer->normalize([], $this->http, $this->http));
    }

    #[Test]
    public function normalizesSingleFile(): void
    {
        $tmp = $this->makeTempFile('hello');
        $files = [
            'avatar' => [
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => 5,
            ],
        ];

        $result = $this->normalizer->normalize($files, $this->http, $this->http);
        $file = $result['avatar'];

        self::assertInstanceOf(UploadedFileInterface::class, $file);
        self::assertSame('photo.jpg', $file->getClientFilename());
        self::assertSame('image/jpeg', $file->getClientMediaType());
        self::assertSame(5, $file->getSize());
        self::assertSame(UPLOAD_ERR_OK, $file->getError());
        self::assertSame('hello', (string) $file->getStream());
    }

    #[Test]
    public function normalizesMultipleFlatFiles(): void
    {
        $tmp1 = $this->makeTempFile('one');
        $tmp2 = $this->makeTempFile('two');
        $files = [
            'docs' => [
                'name' => ['a.txt', 'b.txt'],
                'type' => ['text/plain', 'text/plain'],
                'tmp_name' => [$tmp1, $tmp2],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [3, 3],
            ],
        ];

        $result = $this->normalizer->normalize($files, $this->http, $this->http);

        self::assertIsArray($result['docs']);
        self::assertCount(2, $result['docs']);

        $first = $result['docs'][0];
        $second = $result['docs'][1];
        self::assertInstanceOf(UploadedFileInterface::class, $first);
        self::assertInstanceOf(UploadedFileInterface::class, $second);
        self::assertSame('a.txt', $first->getClientFilename());
        self::assertSame('one', (string) $first->getStream());
        self::assertSame('b.txt', $second->getClientFilename());
        self::assertSame('two', (string) $second->getStream());
    }

    #[Test]
    public function normalizesDeeplyNestedFields(): void
    {
        $tmp = $this->makeTempFile('deep');
        $files = [
            'upload' => [
                'name' => ['a' => ['b' => ['deep.txt']]],
                'type' => ['a' => ['b' => ['text/plain']]],
                'tmp_name' => ['a' => ['b' => [$tmp]]],
                'error' => ['a' => ['b' => [UPLOAD_ERR_OK]]],
                'size' => ['a' => ['b' => [4]]],
            ],
        ];

        $result = $this->normalizer->normalize($files, $this->http, $this->http);

        self::assertIsArray($result['upload']);
        self::assertIsArray($result['upload']['a']);
        self::assertIsArray($result['upload']['a']['b']);

        $file = $result['upload']['a']['b'][0];
        self::assertInstanceOf(UploadedFileInterface::class, $file);
        self::assertSame('deep.txt', $file->getClientFilename());
        self::assertSame('deep', (string) $file->getStream());
    }

    #[Test]
    public function passesThroughUploadError(): void
    {
        $files = [
            'broken' => [
                'name' => 'big.zip',
                'type' => 'application/zip',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 0,
            ],
        ];

        $result = $this->normalizer->normalize($files, $this->http, $this->http);
        $file = $result['broken'];

        self::assertInstanceOf(UploadedFileInterface::class, $file);
        self::assertSame(UPLOAD_ERR_INI_SIZE, $file->getError());
        self::assertSame('big.zip', $file->getClientFilename());
        self::assertSame('application/zip', $file->getClientMediaType());
    }

    #[Test]
    public function passesThroughNestedUploadErrors(): void
    {
        $tmp = $this->makeTempFile('ok');
        $files = [
            'gallery' => [
                'name' => ['good.png', 'missing.png'],
                'type' => ['image/png', 'image/png'],
                'tmp_name' => [$tmp, ''],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
                'size' => [2, 0],
            ],
        ];

        $result = $this->normalizer->normalize($files, $this->http, $this->http);

        self::assertIsArray($result['gallery']);
        $good = $result['gallery'][0];
        $missing = $result['gallery'][1];
        self::assertInstanceOf(UploadedFileInterface::class, $good);
        self::assertInstanceOf(UploadedFileInterface::class, $missing);
        self::assertSame(UPLOAD_ERR_OK, $good->getError());
        self::assertSame('ok', (string) $good->getStream());
        self::assertSame(UPLOAD_ERR_NO_FILE, $missing->getError());
    }

    #[Test]
    public function passesThroughAlreadyNormalizedFiles(): void
    {
        $file = $this->http->createUploadedFile(
            $this->http->createStream('x'),
            1,
            UPLOAD_ERR_OK,
            'kept.txt',
            'text/plain',
        );

        $result = $this->normalizer->normalize(['doc' => $file], $this->http, $this->http);

        self::assertSame($file, $result['doc']);
    }

    #[Test]
    public function recursesIntoGroupedArraysWithoutTmpName(): void
    {
        $tmp = $this->makeTempFile('grouped');
        $files = [
            'form' => [
                'avatar' => [
                    'name' => 'a.txt',
                    'type' => 'text/plain',
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => 7,
                ],
            ],
        ];

        $result = $this->normalizer->normalize($files, $this->http, $this->http);

        self::assertIsArray($result['form']);
        $file = $result['form']['avatar'];
        self::assertInstanceOf(UploadedFileInterface::class, $file);
        self::assertSame('a.txt', $file->getClientFilename());
        self::assertSame('grouped', (string) $file->getStream());
    }

    #[Test]
    public function skipsNonArrayEntries(): void
    {
        $result = $this->normalizer->normalize(
            ['bogus' => 'not-a-file', 'number' => 42],
            $this->http,
            $this->http,
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function fallsBackToEmptyStreamWhenTempFileUnreadable(): void
    {
        $files = [
            'ghost' => [
                'name' => 'ghost.txt',
                'type' => 'text/plain',
                'tmp_name' => '/nonexistent/sfpm/does-not-exist',
                'error' => UPLOAD_ERR_OK,
                'size' => 10,
            ],
        ];

        $result = $this->normalizer->normalize($files, $this->http, $this->http);
        $file = $result['ghost'];

        self::assertInstanceOf(UploadedFileInterface::class, $file);
        self::assertSame(UPLOAD_ERR_OK, $file->getError());
        self::assertSame('', (string) $file->getStream());
    }

    private function makeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sfpm');
        if ($path === false) {
            self::fail('Could not create a temporary file for the test.');
        }

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
