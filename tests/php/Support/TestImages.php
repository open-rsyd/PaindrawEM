<?php

namespace OPEN\PainDraw\Tests\Support;

/**
 * Small image fixtures generated in code, so no binary files are needed.
 */
final class TestImages
{
    /** A real, decodable 1x1 PNG (base64). */
    public const TINY_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public static function tinyPng(): string
    {
        return base64_decode(self::TINY_PNG_BASE64, true);
    }

    /**
     * A PNG header declaring the given dimensions (IHDR + IEND, no pixel data).
     * That is enough for getimagesizefromstring(), which is all the module
     * uses to read the native size of the background image.
     */
    public static function pngWithSize(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string =>
            pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IEND', '');
    }
}
