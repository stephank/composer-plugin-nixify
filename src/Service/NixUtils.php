<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Nixify\Service;

use function ord;

use const STR_PAD_LEFT;

final class NixUtils
{
    private const CHARSET = '0123456789abcdfghijklmnpqrsvwxyz';

    /**
     * Nix-compatible hash compression.
     */
    public function compressHash(string $hash, int $size): string
    {
        return implode(
            '',
            array_map(
                'chr',
                array_reduce(
                    array_chunk(
                        array_map(
                            'ord',
                            str_split($hash)
                        ),
                        1,
                        true
                    ),
                    static function (array $carry, array $byte) use ($size): array {
                        $carry[key($byte) % $size] ^= current($byte);

                        return $carry;
                    },
                    array_fill(0, $size, 0)
                )
            )
        );
    }

    /**
     * Compute the Nix store path for a fixed-output derivation.
     */
    public function computeFixedOutputStorePath(
        string $name,
        string $hashAlgorithm,
        string $hashHex,
        string $storePath = '/nix/store'
    ): string {
        return sprintf(
            '%s/%s-%s',
            $storePath,
            $this
                ->encodeBase32(
                    $this
                        ->compressHash(
                            hash(
                                'sha256',
                                sprintf(
                                    'output:out:sha256:%s:%s:%s',
                                    hash(
                                        'sha256',
                                        sprintf(
                                            'fixed:out:%s:%s:',
                                            $hashAlgorithm,
                                            $hashHex
                                        )
                                    ),
                                    $storePath,
                                    $name
                                ),
                                true
                            ),
                            20
                        )
                ),
            $name
        );
    }

    /**
     * Nix-compatible base32 encoding.
     *
     * This is probably a super inefficient implementation, but we only process
     * small inputs. (20 bytes)
     */
    public function encodeBase32(string $bin): string
    {
        return array_reduce(
            array_map(
                static fn (array $chunk): int => (int) bindec(implode('', $chunk)),
                array_chunk(
                    str_split(
                        array_reduce(
                            array_reverse(str_split($bin)),
                            static fn (string $carry, string $byte): string => sprintf(
                                '%s%s',
                                $carry,
                                str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT)
                            ),
                            ''
                        )
                    ),
                    5
                )
            ),
            static fn (string $c, int $b): string => sprintf(
                '%s%s',
                $c,
                self::CHARSET[$b]
            ),
            ''
        );
    }
}
