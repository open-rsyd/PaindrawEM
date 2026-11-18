<?php

namespace OPEN\PainDraw\Tests;

use OPEN\PainDraw\Tests\Support\PainDrawTestCase;
use OPEN\PainDraw\Tests\Support\TestImages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * redcap_save_record(): turns the data posted by paindraw.js into a JSON file
 * (and optionally a PNG file) stored on the record.
 */
class SaveRecordTest extends PainDrawTestCase
{
    private function postStrokes(array $strokes): void
    {
        $_POST['paindraw_stored_strokes'] = json_encode($strokes);
    }

    private function postPng(string $binary): void
    {
        $_POST['paindraw_stored_png'] = 'data:image/png;base64,' . base64_encode($binary);
    }

    /** @return array<string, mixed> */
    private function storedJson(int $index = 0): array
    {
        $this->assertArrayHasKey($index, \REDCap::$stored, 'no file was stored at index ' . $index);
        return json_decode(\REDCap::$stored[$index]['content'], true, 512, JSON_THROW_ON_ERROR);
    }

    // ----------------------------------------------------------- nothing to save

    public function testDoesNothingWhenNoStrokesWerePosted(): void
    {
        $module = $this->makeModule([self::canvasGroup()]);

        $this->saveRecord($module);

        $this->assertSame([], \REDCap::$stored);
        $this->assertSame([], \REDCap::$linked);
    }

    public function testDoesNothingWhenTheStrokeFieldIsEmpty(): void
    {
        $_POST['paindraw_stored_strokes'] = '';

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $this->assertSame([], \REDCap::$stored);
    }

    public function testDoesNothingForMalformedJson(): void
    {
        $_POST['paindraw_stored_strokes'] = '{this is not json';

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $this->assertSame([], \REDCap::$stored);
        $this->assertArrayNotHasKey('paindraw_stored_strokes', $_POST);
    }

    public function testDoesNothingAndLogsWhenAStrokeFailsValidation(): void
    {
        $this->postStrokes([self::validStroke(['tool' => 'brush'])]);

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $this->assertSame([], \REDCap::$stored);
        $this->assertStringContainsString('PainDraw REDCap external module', $this->loggedErrors());
        $this->assertArrayNotHasKey('paindraw_stored_strokes', $_POST);
    }

    public function testDoesNothingForAnInstrumentWithoutACanvas(): void
    {
        $this->postStrokes([self::validStroke()]);

        $this->saveRecord($this->makeModule([self::canvasGroup(['instrument' => 'other_form'])]));

        $this->assertSame([], \REDCap::$stored);
    }

    // ------------------------------------------------------------- JSON file

    public function testStoresTheDrawingAsAJsonFileLinkedToTheCanvasField(): void
    {
        $this->postStrokes([self::validStroke(['strokeId' => 1]), self::validStroke(['strokeId' => 2, 'tool' => 'pen'])]);

        $this->saveRecord($this->makeModule([self::canvasGroup(['canvas_width' => '640', 'canvas_height' => '480'])]));

        $this->assertCount(1, \REDCap::$stored);
        $file = \REDCap::$stored[0];
        $this->assertSame(7, $file['pid']);
        $this->assertStringEndsWith('.json', $file['name']);

        $this->assertCount(1, \REDCap::$linked);
        $link = \REDCap::$linked[0];
        $this->assertSame($file['docId'], $link['docId']);
        $this->assertSame(7, $link['pid']);
        $this->assertSame('5', $link['record']);
        $this->assertSame('pain_draw', $link['field']);
        $this->assertSame(3, $link['event']);
        $this->assertSame(1, $link['instance']);
    }

    public function testJsonDocumentHasTheDocumentedStructure(): void
    {
        $this->postStrokes([self::validStroke(['strokeId' => 1]), self::validStroke(['strokeId' => 2, 'tool' => 'pen'])]);
        $module = $this->makeModule([self::canvasGroup([
            'canvas_width' => '640',
            'canvas_height' => '480',
            'image_file_name' => 'front.png',
            'image_md5' => 'abc123',
        ])]);

        $this->saveRecord($module);

        $doc = $this->storedJson();
        $this->assertSame(
            ['v', 'id', 'w', 'h', 'units', 'tpl', 'tpl_md5', 'view', 'app', 'ts', 'crc', 's'],
            array_keys($doc)
        );
        $this->assertSame('paindraw_v0.3', $doc['v']);
        $this->assertMatchesRegularExpression('/^PID7-R5/', $doc['id']);
        $this->assertSame(640, $doc['w']);
        $this->assertSame(480, $doc['h']);
        $this->assertSame('px', $doc['units']);
        $this->assertSame('front.png', $doc['tpl']);
        $this->assertSame('abc123', $doc['tpl_md5']);
        $this->assertNull($doc['view']);
        $this->assertSame('PainDraw EM', $doc['app']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $doc['ts']);
        $this->assertNull($doc['crc']);
        $this->assertSame([1, 2], array_column($doc['s'], 'strokeId'));
        $this->assertSame(['spray', 'pen'], array_column($doc['s'], 'tool'));
    }

    public function testFileNameIsTheDrawingIdAndTheFileEndsWithCrLf(): void
    {
        $this->postStrokes([self::validStroke()]);

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $doc = $this->storedJson();
        $this->assertSame($doc['id'] . '.json', \REDCap::$stored[0]['name']);
        $this->assertStringEndsWith("\r\n", \REDCap::$stored[0]['content']);
    }

    public function testTheStrokeDataIsRemovedFromPostAfterProcessing(): void
    {
        $this->postStrokes([self::validStroke()]);

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $this->assertArrayNotHasKey('paindraw_stored_strokes', $_POST);
    }

    public function testStoredStrokesAreTheValidatedOnes(): void
    {
        $this->postStrokes([self::validStroke(['evil' => 'x'])]);

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $this->assertArrayNotHasKey('evil', $this->storedJson()['s'][0]);
    }

    public function testLogsAnErrorWhenREDCapCannotStoreTheFile(): void
    {
        \REDCap::$failStore = true;
        \REDCap::$linkResult = false;
        $this->postStrokes([self::validStroke()]);

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $this->assertStringContainsString('Not able to store json data', $this->loggedErrors());
    }

    // -------------------------------------------------------------- PNG file

    public function testStoresAPngCopyInTheImageStoreField(): void
    {
        $png = TestImages::tinyPng();
        $this->postStrokes([self::validStroke()]);
        $this->postPng($png);

        $this->saveRecord($this->makeModule([self::canvasGroup(['image_store_field' => 'pain_png'])]));

        $this->assertCount(2, \REDCap::$stored);
        $this->assertStringEndsWith('.json', \REDCap::$stored[0]['name']);
        $this->assertStringEndsWith('.png', \REDCap::$stored[1]['name']);
        $this->assertSame($png, \REDCap::$stored[1]['content']);
        $this->assertSame('pain_draw', \REDCap::$linked[0]['field']);
        $this->assertSame('pain_png', \REDCap::$linked[1]['field']);
        $this->assertSame(\REDCap::$stored[1]['docId'], \REDCap::$linked[1]['docId']);
        $this->assertArrayNotHasKey('paindraw_stored_png', $_POST);
    }

    public function testPngAndJsonShareTheSameDrawingId(): void
    {
        $this->postStrokes([self::validStroke()]);
        $this->postPng(TestImages::tinyPng());

        $this->saveRecord($this->makeModule([self::canvasGroup(['image_store_field' => 'pain_png'])]));

        $id = $this->storedJson()['id'];
        $this->assertSame($id . '.png', \REDCap::$stored[1]['name']);
    }

    public function testBase64WithLineBreaksIsDecoded(): void
    {
        $png = TestImages::tinyPng();
        $this->postStrokes([self::validStroke()]);
        $_POST['paindraw_stored_png'] = "data:image/png;base64,\n" . chunk_split(base64_encode($png), 16, "\n");

        $this->saveRecord($this->makeModule([self::canvasGroup(['image_store_field' => 'pain_png'])]));

        $this->assertSame($png, \REDCap::$stored[1]['content']);
    }

    public function testPngIsNotStoredWhenNoImageStoreFieldIsConfigured(): void
    {
        $this->postStrokes([self::validStroke()]);
        $this->postPng(TestImages::tinyPng());

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $this->assertCount(1, \REDCap::$stored);
    }

    public function testOnlyPngDataUrlsAreAccepted(): void
    {
        $this->postStrokes([self::validStroke()]);
        $_POST['paindraw_stored_png'] = 'data:image/jpeg;base64,' . base64_encode(TestImages::tinyPng());

        $this->saveRecord($this->makeModule([self::canvasGroup(['image_store_field' => 'pain_png'])]));

        $this->assertCount(1, \REDCap::$stored, 'only the JSON file should be stored');
        $this->assertStringEndsWith('.json', \REDCap::$stored[0]['name']);
    }

    #[RequiresPhpExtension('imagick')]
    public function testPngIsReEncodedWhenImagickIsAvailable(): void
    {
        $this->postStrokes([self::validStroke()]);
        $this->postPng(TestImages::tinyPng());
        $module = $this->makeModule([self::canvasGroup(['image_store_field' => 'pain_png'])]);
        $module->imagickAvailable = true;

        $this->saveRecord($module);

        $this->assertCount(2, \REDCap::$stored);
        $this->assertStringStartsWith("\x89PNG", \REDCap::$stored[1]['content']);
    }

    // --------------------------------------------------------- regression tests for fixed bugs

    /**
     * README: id = PID<Project>-R<Record>-F<CanvasField>-E<Event>-I<Instance>.
     * The code builds "...-R{record}F-{field}-..." (misplaced hyphen).
     */
    public function testDrawingIdFollowsTheFormatInTheReadme(): void
    {
        $this->postStrokes([self::validStroke()]);

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $this->assertSame('PID7-R5-Fpain_draw-E3-I1', $this->storedJson()['id']);
    }

    /**
     * A canvas with an unsupported image has no dimensions or template, but
     * array_key_exists('img_url', $cfg) is still true, so a crafted POST is
     * stored with null width/height/template.
     */
    public function testNothingIsStoredForACanvasWithAnUnsupportedImage(): void
    {
        $this->postStrokes([self::validStroke()]);

        $this->saveRecord($this->makeModule([self::canvasGroup(['format_error' => 1])]));

        $this->assertSame([], \REDCap::$stored);
    }

    /**
     * $_POST['paindraw_stored_png'] is read without isset(); when the field is
     * missing PHP raises "Undefined array key".
     */
    public function testMissingPngFieldDoesNotRaiseWarnings(): void
    {
        $this->postStrokes([self::validStroke()]);
        $module = $this->makeModule([self::canvasGroup(['image_store_field' => 'pain_png'])]);

        $this->withWarningsAsExceptions(function () use ($module): void {
            $this->saveRecord($module);
        });

        $this->assertCount(1, \REDCap::$stored);
    }

    /**
     * An undecodable PNG payload throws a plain \Exception from inside the
     * save hook, which would abort the user's save in REDCap.
     */
    public function testUndecodablePngDoesNotThrowFromTheSaveHook(): void
    {
        $this->postStrokes([self::validStroke()]);
        $_POST['paindraw_stored_png'] = 'data:image/png;base64,!!!not*base64!!!';
        $module = $this->makeModule([self::canvasGroup(['image_store_field' => 'pain_png'])]);

        $this->saveRecord($module);

        $this->assertCount(1, \REDCap::$stored);
    }

    /**
     * Valid JSON that is not a list (a number, string, true, null) is passed
     * to validateJsonInput(array $data) and causes an uncaught TypeError.
     */
    #[DataProvider('scalarJsonProvider')]
    public function testScalarJsonDoesNotBreakTheSaveHook(string $payload): void
    {
        $_POST['paindraw_stored_strokes'] = $payload;

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $this->assertSame([], \REDCap::$stored);
    }

    public static function scalarJsonProvider(): array
    {
        return [
            'number' => ['5'],
            'float' => ['1.5'],
            'string' => ['"abc"'],
            'true' => ['true'],
            'null' => ['null'],
        ];
    }
}
