<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Nixify\Service;

use Generator;
use Nixify\Service\NixUtils;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class NixutilsTest extends TestCase
{
    public function compressHashDataProvider(): Generator
    {
        yield [
            'abcde',
            3,
            implode('', array_map('chr', [5, 7, 99])),
        ];

        yield [
            'hello world',
            4,
            implode('', array_map('chr', [117, 41, 127, 3])),
        ];
    }

    public function computeFixedOutputStorePathDataProvider()
    {
        yield [
            'hello',
            'sha256',
            'abc',
            '/nix/store',
            '/nix/store/h4nrzjifsmamf92gvxgil3hd34pb3r99-hello',
        ];
    }

    public function encodeBase32DataProvider(): Generator
    {
        yield [
            'hello',
            'dxn6qrb8',
        ];
    }

    /**
     * @dataProvider compressHashDataProvider
     */
    public function testCompressHash(string $input, int $size, string $expected)
    {
        $subject = new NixUtils();

        self::assertEquals(
            $expected,
            $subject->compressHash($input, $size)
        );
    }

    /**
     * @dataProvider computeFixedOutputStorePathDataProvider
     */
    public function testComputeFixedOutputStorePath(string $name, string $hashAlgo, string $hashHex, string $storePath, string $expected)
    {
        $subject = new NixUtils();

        self::assertEquals(
            $expected,
            $subject->computeFixedOutputStorePath($name, $hashAlgo, $hashHex, $storePath)
        );
    }

    /**
     * @dataProvider encodeBase32DataProvider
     */
    public function testEncodeBase32(string $input, string $expected)
    {
        $subject = new NixUtils();

        self::assertEquals(
            $expected,
            $subject->encodeBase32($input)
        );
    }
}
