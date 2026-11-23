<?php

namespace OPEN\PaindrawEM\Tests;

use OPEN\PaindrawEM\Tests\Support\PainDrawTestCase;
use OPEN\PaindrawEM\Tests\Support\TestImages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * redcap_module_save_configuration(): validates the uploaded background
 * image and derives canvas dimensions from it.
 */
class SaveConfigurationTest extends PainDrawTestCase
{
    /**
     * Run the hook for one canvas group and return the saved settings.
     *
     * @return array<string, array<int, mixed>>
     */
    private function runWith(string $mime, string $content, string $width = '', string $height = ''): array
    {
        \REDCap::addFakeFile(42, $mime, 'body.png', $content);

        $module = $this->makeModule();
        $module->fakeProjectSettings = [
            'image_file' => [42],
            'image_file_name' => [null],
            'image_md5' => [null],
            'format_error' => [null],
            'canvas_width' => [$width],
            'canvas_height' => [$height],
        ];
        $module->redcap_module_save_configuration(7);

        $this->assertNotNull($module->savedProjectSettings, 'setProjectSettings() was not called');
        return $module->savedProjectSettings;
    }

    public function testStoresChecksumAndFileNameForASupportedImage(): void
    {
        $png = TestImages::pngWithSize(300, 200);

        $saved = $this->runWith('image/png', $png);

        $this->assertSame(0, $saved['format_error'][0]);
        $this->assertSame(md5($png), $saved['image_md5'][0]);
        $this->assertSame('body.png', $saved['image_file_name'][0]);
    }

    #[DataProvider('supportedMimeProvider')]
    public function testAllDocumentedMimeTypesAreAccepted(string $mime): void
    {
        $saved = $this->runWith($mime, TestImages::pngWithSize(300, 200));

        $this->assertSame(0, $saved['format_error'][0]);
    }

    public static function supportedMimeProvider(): array
    {
        return [
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'avif' => ['image/avif'],
            'bmp' => ['image/bmp'],
        ];
    }

    #[DataProvider('unsupportedMimeProvider')]
    public function testUnsupportedFileTypesAreFlaggedAndDimensionsCleared(string $mime): void
    {
        $saved = $this->runWith($mime, 'irrelevant', '500', '400');

        $this->assertSame(1, $saved['format_error'][0]);
        $this->assertSame('', $saved['image_md5'][0]);
        $this->assertSame('', $saved['canvas_width'][0]);
        $this->assertSame('', $saved['canvas_height'][0]);
        $this->assertSame('body.png', $saved['image_file_name'][0]);
    }

    public static function unsupportedMimeProvider(): array
    {
        return [
            'gif' => ['image/gif'],
            'svg' => ['image/svg+xml'],
            'pdf' => ['application/pdf'],
            'text' => ['text/plain'],
        ];
    }

    #[DataProvider('dimensionProvider')]
    public function testCanvasDimensionsAreDerivedFromTheImage(
        int $nativeWidth,
        int $nativeHeight,
        string $width,
        string $height,
        string $expectedWidth,
        string $expectedHeight
    ): void {
        $saved = $this->runWith('image/png', TestImages::pngWithSize($nativeWidth, $nativeHeight), $width, $height);

        $this->assertSame($expectedWidth, $saved['canvas_width'][0]);
        $this->assertSame($expectedHeight, $saved['canvas_height'][0]);
    }

    public static function dimensionProvider(): array
    {
        return [
            // native w, native h, configured w, configured h => expected w, expected h
            'blank: native size is used' => [600, 800, '', '', '600', '800'],
            'blank: wide image is clamped to 796 px' => [1000, 500, '', '', '796', '398'],
            'blank: image exactly at the limit' => [796, 400, '', '', '796', '400'],
            'only width: height keeps the aspect ratio' => [800, 600, '400', '', '400', '300'],
            'only height: width keeps the aspect ratio' => [800, 600, '', '300', '400', '300'],
            'only height: derived width is clamped to 796 px' => [800, 400, '', '1000', '796', '398'],
            'both given: left untouched' => [800, 600, '500', '500', '500', '500'],
            'zero counts as blank' => [600, 800, '0', '0', '600', '800'],
        ];
    }

    public function testEachCanvasGroupIsHandledIndependently(): void
    {
        \REDCap::addFakeFile(1, 'image/png', 'a.png', TestImages::pngWithSize(300, 200));
        \REDCap::addFakeFile(2, 'image/gif', 'b.gif', 'GIF89a');

        $module = $this->makeModule();
        $module->fakeProjectSettings = [
            'image_file' => [1, 2],
            'image_file_name' => [null, null],
            'image_md5' => [null, null],
            'format_error' => [null, null],
            'canvas_width' => ['', ''],
            'canvas_height' => ['', ''],
        ];
        $module->redcap_module_save_configuration(7);
        $saved = $module->savedProjectSettings;

        $this->assertSame([0, 1], $saved['format_error']);
        $this->assertSame(['a.png', 'b.gif'], $saved['image_file_name']);
        $this->assertSame('300', $saved['canvas_width'][0]);
        $this->assertSame('', $saved['canvas_width'][1]);
    }

    // ---------------------------------------------------- regression tests for fixed bugs

    /**
     * A file with an image MIME type whose content cannot be decoded makes
     * getimagesizefromstring() return false. The module then reads array
     * offsets from false and ends up with blank dimensions, instead of
     * reporting a format error.
     */
    public function testUndecodableImageIsReportedAsFormatError(): void
    {
        $this->withWarningsAsExceptions(function (): void {
            $saved = $this->runWith('image/png', 'this is not really a png');

            $this->assertSame(1, $saved['format_error'][0]);
        });
    }
}
