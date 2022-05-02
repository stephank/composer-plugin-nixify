<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Nixify;

use function ord;

use const STR_PAD_LEFT;

final class NixUtils
{
    private const CHARSET = '0123456789abcdfghijklmnpqrsvwxyz';

    /**
     * Nix-compatible hash compression.
     */
    public static function compressHash(string $hash, int $size): string
    {
        $result = array_fill(0, $size, 0);

        foreach (str_split($hash) as $idx => $byte) {
            $result[$idx % $size] ^= ord($byte);
        }

        return implode('', array_map('chr', $result));
    }

    /**
     * Compute the Nix store path for a fixed-output derivation.
     */
    public static function computeFixedOutputStorePath(
        string $name,
        string $hashAlgorithm,
        string $hashHex,
        string $storePath = '/nix/store'
    ) {
        $innerStr = "fixed:out:{$hashAlgorithm}:{$hashHex}:";
        $innerHashHex = hash('sha256', $innerStr);

        $outerStr = "output:out:sha256:{$innerHashHex}:{$storePath}:{$name}";
        $outerHash = hash('sha256', $outerStr, true);
        $outerHash32 = self::encodeBase32(self::compressHash($outerHash, 20));

        return "{$storePath}/{$outerHash32}-{$name}";
    }

    /**
     * Nix-compatible base32 encoding.
     *
     * This is probably a super inefficient implementation, but we only process
     * small inputs. (20 bytes)
     */
    public static function encodeBase32(string $bin): string
    {
        $bits = '';

        foreach (array_reverse(str_split($bin)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';

        while ($bits) {
            $result .= self::CHARSET[bindec(substr($bits, 0, 5))];
            $bits = substr($bits, 5);
        }

        return $result;
    }
}
