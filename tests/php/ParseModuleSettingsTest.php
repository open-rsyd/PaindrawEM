<?php

namespace OPEN\PainDraw\Tests;

use OPEN\PainDraw\Tests\Support\PainDrawTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Translation of the stored module configuration into the settings object
 * that is handed to paindraw.js.
 */
class ParseModuleSettingsTest extends PainDrawTestCase
{
    public function testReturnsNothingWhenNoCanvasIsConfigured(): void
    {
        $this->assertSame([], $this->makeModule([])->parseSettings(7, 'pain_form'));
    }

    public function testReturnsNothingForAnInstrumentWithoutACanvas(): void
    {
        $module = $this->makeModule([self::canvasGroup(['instrument' => 'other_form'])]);

        $this->assertSame([], $module->parseSettings(7, 'pain_form'));
    }

    public function testReturnsNothingWhenTheGroupHasNoImage(): void
    {
        $module = $this->makeModule([self::canvasGroup(['image_file' => null])]);

        $this->assertSame([], $module->parseSettings(7, 'pain_form'));
    }

    public function testPicksTheGroupBelongingToTheInstrument(): void
    {
        $module = $this->makeModule([
            self::canvasGroup(['instrument' => 'first_form', 'canvas_field' => 'first_field', 'image_file' => 1]),
            self::canvasGroup(['instrument' => 'pain_form', 'canvas_field' => 'second_field', 'image_file' => 2]),
        ]);

        $cfg = $module->parseSettings(7, 'pain_form');

        $this->assertSame('second_field', $cfg['canvas_field']);
        $this->assertStringContainsString('doc_id_hash=hash2&', $cfg['img_url']);
    }

    public function testDefaultsAreUsedWhenOptionalSettingsAreEmpty(): void
    {
        $cfg = $this->makeModule([self::canvasGroup()])->parseSettings(7, 'pain_form');

        $this->assertSame('spray', $cfg['draw_tool']);
        $this->assertSame(3, $cfg['line_width']);
        $this->assertSame('#fc3d03', $cfg['line_color']);
        $this->assertSame(5, $cfg['spray_radius']);
        $this->assertSame(15, $cfg['spray_density']);
        $this->assertSame(0.1, $cfg['alpha']); // 90 % transparency
        $this->assertArrayNotHasKey('image_store_field', $cfg);
    }

    public function testConfiguredValuesAreTranslated(): void
    {
        $cfg = $this->makeModule([self::canvasGroup([
            'draw_tool' => 'pen',
            'line_width' => '6',
            'line_color' => '#00aaff',
            'spray_radius' => '9',
            'spray_density' => '30',
            'image_store_field' => 'pain_png',
        ])])->parseSettings(7, 'pain_form');

        $this->assertSame('pen', $cfg['draw_tool']);
        $this->assertSame(6, $cfg['line_width']);
        $this->assertSame('#00aaff', $cfg['line_color']);
        $this->assertSame(9, $cfg['spray_radius']);
        $this->assertSame(30, $cfg['spray_density']);
        $this->assertSame('pain_png', $cfg['image_store_field']);
    }

    public function testCarriesCanvasTemplateAndDimensions(): void
    {
        $cfg = $this->makeModule([self::canvasGroup([
            'canvas_width' => '640',
            'canvas_height' => '480',
            'image_file_name' => 'front.png',
            'image_md5' => 'abc123',
        ])])->parseSettings(7, 'pain_form');

        $this->assertSame('pain_draw', $cfg['canvas_field']);
        $this->assertSame(640, $cfg['canvas_width']);
        $this->assertSame(480, $cfg['canvas_height']);
        $this->assertSame('front.png', $cfg['tpl']);
        $this->assertSame('abc123', $cfg['tpl_md5']);
    }

    #[DataProvider('transparencyProvider')]
    public function testTransparencyPercentIsConvertedToAlpha(string $percent, float $expectedAlpha): void
    {
        $cfg = $this->makeModule([self::canvasGroup(['transparancy' => $percent])])->parseSettings(7, 'pain_form');

        $this->assertSame($expectedAlpha, $cfg['alpha']);
    }

    public static function transparencyProvider(): array
    {
        return [
            'opaque' => ['0', 1.0],
            'quarter' => ['25', 0.75],
            'half' => ['50', 0.5],
            'default value' => ['90', 0.1],
            'fully transparent' => ['100', 0.0],
            'rounded to two decimals' => ['33', 0.67],
        ];
    }

    #[DataProvider('validColourProvider')]
    public function testValidHexColoursAreKept(string $colour): void
    {
        $cfg = $this->makeModule([self::canvasGroup(['line_color' => $colour])])->parseSettings(7, 'pain_form');

        $this->assertSame($colour, $cfg['line_color']);
    }

    public static function validColourProvider(): array
    {
        return [
            'six digits lower case' => ['#00aaff'],
            'six digits upper case' => ['#00AAFF'],
            'three digits' => ['#0af'],
        ];
    }

    #[DataProvider('invalidColourProvider')]
    public function testInvalidColoursFallBackToDefault(string $colour): void
    {
        $cfg = $this->makeModule([self::canvasGroup(['line_color' => $colour])])->parseSettings(7, 'pain_form');

        $this->assertSame('#fc3d03', $cfg['line_color']);
    }

    public static function invalidColourProvider(): array
    {
        return [
            'colour name' => ['red'],
            'missing hash' => ['ff0000'],
            'wrong length' => ['#ff'],
            'non hex digits' => ['#gg0000'],
        ];
    }

    public function testImageUrlPointsAtREDCapsImageViewer(): void
    {
        $cfg = $this->makeModule([self::canvasGroup(['image_file' => 42])])->parseSettings(7, 'pain_form');

        $this->assertSame(
            APP_PATH_WEBROOT . 'DataEntry/image_view.php?doc_id_hash=hash42&pid=7&id=42',
            $cfg['img_url']
        );
    }

    public function testUnsupportedImageFormatDisablesTheCanvas(): void
    {
        $cfg = $this->makeModule([self::canvasGroup(['format_error' => 1])])->parseSettings(7, 'pain_form');

        $this->assertFalse($cfg['img_url']);
        $this->assertSame('pain_draw', $cfg['canvas_field']);
        $this->assertArrayNotHasKey('canvas_width', $cfg);
    }

    // --------------------------------------------------------- known bugs (opt-in)

    /**
     * The colour pattern in parseModuleSettings() is not anchored, so any
     * string that merely contains a hex colour is accepted unchanged.
     */
    #[Group('known-bug')]
    public function testColourSurroundedByOtherTextIsNotAccepted(): void
    {
        $cfg = $this->makeModule([self::canvasGroup(['line_color' => 'xx#abcxx'])])->parseSettings(7, 'pain_form');

        $this->assertSame('#fc3d03', $cfg['line_color']);
    }

    /**
     * The settings accept #RGB, but validateJsonInput() only accepts #RRGGBB.
     * A project configured with "#f00" would have every stroke rejected on
     * save, and the module only logs the error, so data silently disappears.
     */
    #[Group('known-bug')]
    public function testConfiguredColourIsAcceptedByTheServerSideValidator(): void
    {
        $module = $this->makeModule([self::canvasGroup(['line_color' => '#f00'])]);
        $cfg = $module->parseSettings(7, 'pain_form');

        // paindraw.js posts whatever colour the settings contain.
        $stroke = self::validStroke(['color' => $cfg['line_color']]);

        $this->assertCount(1, $module->validate(self::viaJson([$stroke])));
    }
}
