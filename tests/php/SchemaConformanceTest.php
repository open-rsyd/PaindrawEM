<?php

namespace OPEN\PainDraw\Tests;

use OPEN\PainDraw\Tests\Support\JsonSchemaSubset;
use OPEN\PainDraw\Tests\Support\PainDrawTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every drawing document the module stores must satisfy schema/paindraw_schema_1.0.json.
 */
class SchemaConformanceTest extends PainDrawTestCase
{
    private const SCHEMA = __DIR__ . '/../../schema/paindraw_schema_1.0.json';

    private function schema(): JsonSchemaSubset
    {
        return JsonSchemaSubset::fromFile(self::SCHEMA);
    }

    /** @return string[] violations of the schema by the first stored file */
    private function violationsOfStoredFile(): array
    {
        $this->assertNotEmpty(\REDCap::$stored, 'no file was stored');

        return $this->schema()->validate(json_decode(\REDCap::$stored[0]['content'], false, 512, JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------ documents produced by the module

    #[DataProvider('drawingProvider')]
    public function testStoredDocumentsConformToTheSchema(array $strokes): void
    {
        $_POST['paindraw_stored_strokes'] = json_encode($strokes);

        $this->saveRecord($this->makeModule([self::canvasGroup()]));

        $this->assertSame([], $this->violationsOfStoredFile());
    }

    public static function drawingProvider(): array
    {
        return [
            'one spray stroke' => [[self::validStroke()]],
            'one pen stroke' => [[self::validStroke(['tool' => 'pen', 'sprayDensity' => null, 'sprayRadius' => null])]],
            'touch input' => [[self::validStroke(['drawInputType' => 'touch'])]],
            'several strokes' => [[
                self::validStroke(['strokeId' => 1]),
                self::validStroke(['strokeId' => 2, 'tool' => 'pen', 'sprayDensity' => null, 'sprayRadius' => null, 'alpha' => 0.25]),
                self::validStroke(['strokeId' => 3, 'drawInputType' => 'touch', 'scaleFactor' => 0.5, 'dpr' => 1.5]),
            ]],
        ];
    }

    public function testTheSchemaFileIsValidJsonAndDeclaresVersion1(): void
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('1.0', $schema['properties']['schema_version']['const']);
    }

    // ---------------------------------------- the module's own check agrees with the schema

    #[DataProvider('brokenDocumentProvider')]
    public function testBrokenDocumentsAreRejectedByTheSchemaAndByTheModule(callable $break): void
    {
        $this->postStrokesAndSave();
        $doc = json_decode(\REDCap::$stored[0]['content'], true);
        $doc = $break($doc);

        $bySchema = $this->schema()->validate(json_decode(json_encode($doc), false));
        $this->assertNotSame([], $bySchema, 'the schema should reject this document');

        $this->expectException(\ValidationException::class);
        $this->makeModule()->validateDocument($doc);
    }

    public static function brokenDocumentProvider(): array
    {
        $set = static fn (string $k, mixed $v): callable => static function (array $d) use ($k, $v): array {
            $d[$k] = $v;

            return $d;
        };
        $drop = static fn (string $k): callable => static function (array $d) use ($k): array {
            unset($d[$k]);

            return $d;
        };

        return [
            'wrong schema_version' => [$set('schema_version', '2.0')],
            'two part module_version' => [$set('module_version', '1.0')],
            'zero width' => [$set('width', 0)],
            'float height' => [$set('height', 300.5)],
            'wrong units' => [$set('units', 'mm')],
            'timestamp that is not a date' => [$set('timestamp', 'yesterday')],
            'missing template_md5' => [$drop('template_md5')],
            'short template_md5' => [$set('template_md5', 'abc123')],
        ];
    }

    /**
     * Stroke contents are checked by validateJsonInput() before the document is built, so the
     * document check only needs the schema's header rules. These cases show the schema itself
     * rejects them too.
     */
    #[DataProvider('brokenStrokeProvider')]
    public function testTheSchemaRejectsBrokenStrokes(string $key, mixed $value): void
    {
        $this->postStrokesAndSave();
        $doc = json_decode(\REDCap::$stored[0]['content'], false);
        $doc->strokes[0]->$key = $value;

        $this->assertNotSame([], $this->schema()->validate($doc));
    }

    public static function brokenStrokeProvider(): array
    {
        return [
            'stroke_id zero' => ['stroke_id', 0],
            'unknown tool' => ['tool', 'brush'],
            'colour without hash' => ['color', 'fc3d03'],
            'alpha above 1' => ['alpha', 1.5],
            'scale_factor zero' => ['scale_factor', 0],
            'spray without density' => ['spray_density', null],
            'no points' => ['points', []],
            'point with one coordinate' => ['points', [[1]]],
            'point with three coordinates' => ['points', [[1, 2, 3]]],
            'fractional coordinate' => ['points', [[1.5, 2]]],
        ];
    }

    private function postStrokesAndSave(): void
    {
        $_POST['paindraw_stored_strokes'] = json_encode([self::validStroke()]);
        $this->saveRecord($this->makeModule([self::canvasGroup()]));
    }

    // ------------------------------------------------------------ the validator itself

    #[DataProvider('validatorCases')]
    public function testSubsetValidator(string $schema, string $instance, bool $valid): void
    {
        $validator = new JsonSchemaSubset(json_decode($schema, false));

        $this->assertSame($valid, $validator->validate(json_decode($instance, false)) === []);
    }

    public static function validatorCases(): array
    {
        return [
            'integer accepts 1.0' => ['{"type":"integer"}', '1.0', true],
            'integer rejects 1.5' => ['{"type":"integer"}', '1.5', false],
            'type list' => ['{"type":["string","null"]}', 'null', true],
            'const' => ['{"const":"a"}', '"b"', false],
            'enum' => ['{"enum":["a","b"]}', '"b"', true],
            'pattern anchored' => ['{"pattern":"^a$"}', '"a\n"', false],
            'exclusiveMinimum' => ['{"exclusiveMinimum":0}', '0', false],
            'minimum' => ['{"minimum":1}', '1', true],
            'required' => ['{"required":["a"]}', '{}', false],
            'properties' => ['{"properties":{"a":{"type":"integer"}}}', '{"a":"x"}', false],
            'minItems' => ['{"minItems":1}', '[]', false],
            'prefixItems and items' => ['{"prefixItems":[{"type":"integer"}],"items":{"type":"string"}}', '[1,"a"]', true],
            'items false' => ['{"prefixItems":[{},{}],"items":false}', '[1,2,3]', false],
            'ref' => ['{"$defs":{"n":{"type":"integer"}},"$ref":"#/$defs/n"}', '"x"', false],
            'if then' => ['{"if":{"properties":{"t":{"const":"a"}}},"then":{"required":["x"]}}', '{"t":"a"}', false],
            'if else' => ['{"if":{"properties":{"t":{"const":"a"}}},"else":{"required":["x"]}}', '{"t":"b"}', false],
            'if not matched, no then' => ['{"if":{"properties":{"t":{"const":"a"}}},"then":{"required":["x"]}}', '{"t":"b"}', true],
            'empty object is an object' => ['{"type":"object"}', '{}', true],
            'empty list is not an object' => ['{"type":"object"}', '[]', false],
        ];
    }

    public function testAnUnsupportedKeywordIsReportedInsteadOfIgnored(): void
    {
        $this->expectException(\LogicException::class);
        (new JsonSchemaSubset(json_decode('{"uniqueItems":true}', false)))->validate([1]);
    }
}
