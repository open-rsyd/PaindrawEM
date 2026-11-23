<?php

namespace OPEN\PaindrawEM\Tests;

use OPEN\PaindrawEM\Tests\Support\PainDrawTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Server-side validation of the stroke data posted by the browser.
 * This is the security boundary of the module, so it is tested thoroughly.
 */
class ValidateJsonInputTest extends PainDrawTestCase
{
    // ---------------------------------------------------------------- valid input

    public function testAcceptsASprayStrokeAndNormalisesTypes(): void
    {
        $result = $this->makeModule()->validate(self::viaJson([self::validStroke([
            'strokeId' => 4,
            'drawInputType' => 'touch',
            'scaleFactor' => 0.8542713567839196,
            'dpr' => 1.5,
        ])]));

        $this->assertCount(1, $result);
        $s = $result[0];
        $this->assertSame(4, $s['strokeId']);
        $this->assertSame('touch', $s['drawInputType']);
        $this->assertSame('spray', $s['tool']);
        $this->assertSame('#fc3d03', $s['color']);
        $this->assertSame(3, $s['lineWidth']);
        $this->assertSame(15, $s['sprayDensity']);
        $this->assertSame(5, $s['sprayRadius']);
        $this->assertSame(0.1, $s['alpha']);
        $this->assertSame(0.8542713567839196, $s['scaleFactor']);
        $this->assertSame(1.5, $s['dpr']);
        $this->assertSame([[10, 20], [11, 21]], $s['p']);
    }

    public function testPenStrokeDropsSprayParametersEvenWhenTheClientSendsThem(): void
    {
        // paindraw.js always includes sprayDensity/sprayRadius, also for the pen tool.
        $result = $this->makeModule()->validate(self::viaJson([self::validStroke(['tool' => 'pen'])]));

        $this->assertSame('pen', $result[0]['tool']);
        $this->assertArrayHasKey('sprayDensity', $result[0]);
        $this->assertArrayHasKey('sprayRadius', $result[0]);
        $this->assertNull($result[0]['sprayDensity']);
        $this->assertNull($result[0]['sprayRadius']);
    }

    public function testPenStrokeDoesNotRequireSprayParameters(): void
    {
        $stroke = self::validStroke(['tool' => 'pen']);
        unset($stroke['sprayDensity'], $stroke['sprayRadius']);

        $result = $this->makeModule()->validate(self::viaJson([$stroke]));

        $this->assertSame('pen', $result[0]['tool']);
    }

    public function testKeepsStrokeOrderAndAcceptsSeveralStrokes(): void
    {
        $result = $this->makeModule()->validate(self::viaJson([
            self::validStroke(['strokeId' => 1]),
            self::validStroke(['strokeId' => 2, 'tool' => 'pen']),
            self::validStroke(['strokeId' => 3, 'drawInputType' => 'touch']),
        ]));

        $this->assertSame([1, 2, 3], array_column($result, 'strokeId'));
    }

    public function testEmptyListOfStrokesIsValid(): void
    {
        $this->assertSame([], $this->makeModule()->validate([]));
    }

    public function testUnknownKeysAreDroppedSoClientCannotSmuggleDataIntoTheFile(): void
    {
        $stroke = self::validStroke(['evil' => '<script>alert(1)</script>', 'extra' => ['a' => 1]]);

        $result = $this->makeModule()->validate(self::viaJson([$stroke]));

        $this->assertArrayNotHasKey('evil', $result[0]);
        $this->assertArrayNotHasKey('extra', $result[0]);
    }

    #[DataProvider('validAlphaProvider')]
    public function testAcceptsAlphaWithinZeroToOne(int|float $alpha): void
    {
        $result = $this->makeModule()->validate(self::viaJson([self::validStroke(['alpha' => $alpha])]));

        $this->assertSame((float) $alpha, $result[0]['alpha']);
    }

    public static function validAlphaProvider(): array
    {
        return [
            'zero' => [0],
            'one' => [1],
            'half' => [0.5],
            'two decimals' => [0.05],
            'default' => [0.1],
        ];
    }

    // -------------------------------------------------------------- invalid input

    /**
     * @param array<string, mixed> $overrides
     * @param string[] $unset
     */
    #[DataProvider('invalidStrokeProvider')]
    public function testRejectsInvalidStroke(array $overrides, array $unset): void
    {
        $stroke = array_diff_key(array_replace(self::validStroke(), $overrides), array_flip($unset));

        $this->expectException(\ValidationException::class);
        $this->makeModule()->validate(self::viaJson([$stroke]));
    }

    public static function invalidStrokeProvider(): array
    {
        return [
            // required keys
            'missing strokeId' => [[], ['strokeId']],
            'missing drawInputType' => [[], ['drawInputType']],
            'missing tool' => [[], ['tool']],
            'missing color' => [[], ['color']],
            'missing lineWidth' => [[], ['lineWidth']],
            'missing alpha' => [[], ['alpha']],
            'missing scaleFactor' => [[], ['scaleFactor']],
            'missing dpr' => [[], ['dpr']],
            'missing points' => [[], ['p']],
            'spray without sprayDensity' => [[], ['sprayDensity']],
            'spray without sprayRadius' => [[], ['sprayRadius']],
            'null tool' => [['tool' => null], []],

            // enumerations
            'unknown tool' => [['tool' => 'brush'], []],
            'unknown input type' => [['drawInputType' => 'stylus'], []],

            // colour
            'short hex colour' => [['color' => '#fc0'], []],
            'colour name' => [['color' => 'red'], []],
            'non-hex digits' => [['color' => '#GGGGGG'], []],
            'colour without hash' => [['color' => 'fc3d03'], []],
            'colour with trailing text' => [['color' => '#fc3d03; x'], []],

            // integers
            'negative strokeId' => [['strokeId' => -1], []],
            'zero strokeId (stroke_id starts at 1)' => [['strokeId' => 0], []],
            'fractional strokeId' => [['strokeId' => 1.5], []],
            'text strokeId' => [['strokeId' => 'one'], []],
            'negative lineWidth' => [['lineWidth' => -3], []],
            'fractional lineWidth' => [['lineWidth' => 3.5], []],
            'fractional sprayDensity' => [['sprayDensity' => 1.5], []],
            'negative sprayRadius' => [['sprayRadius' => -5], []],

            // alpha
            'alpha above one' => [['alpha' => 1.5], []],
            'alpha below zero' => [['alpha' => -0.1], []],
            'alpha with three decimals' => [['alpha' => 0.123], []],
            'alpha 2' => [['alpha' => 2], []],

            // scaleFactor
            'negative scaleFactor' => [['scaleFactor' => -1], []],
            'text scaleFactor' => [['scaleFactor' => 'abc'], []],
            'zero scaleFactor' => [['scaleFactor' => 0], []],
            'zero scaleFactor as decimal' => [['scaleFactor' => 0.0], []],
            'zero dpr' => [['dpr' => 0], []],
            'negative dpr' => [['dpr' => -1], []],

            // points
            'points not an array' => [['p' => 'x'], []],
            'empty points' => [['p' => []], []],
            'point with three values' => [['p' => [[1, 2, 3]]], []],
            'point with one value' => [['p' => [[1]]], []],
            'point that is not an array' => [['p' => [5]], []],
            'fractional coordinates' => [['p' => [[1.5, 2]]], []],
            'string coordinates' => [['p' => [['1', '2']]], []],
        ];
    }

    public function testRejectsAStrokeEntryThatIsNotAnObject(): void
    {
        $this->expectException(\ValidationException::class);
        $this->makeModule()->validate(['not a stroke']);
    }

    public function testOneBadStrokeRejectsTheWholeSubmission(): void
    {
        $this->expectException(\ValidationException::class);
        $this->makeModule()->validate(self::viaJson([
            self::validStroke(['strokeId' => 1]),
            self::validStroke(['strokeId' => 2, 'tool' => 'brush']),
        ]));
    }

    // --------------------------------------------------------- regression tests for fixed bugs

    /**
     * PaindrawEM.php line ~342 checks $d['scaleFactor'] instead of $d['dpr'], so
     * the dpr value is never validated.
     */
    public function testDprIsValidatedOnItsOwnValue(): void
    {
        $this->expectException(\ValidationException::class);
        $this->makeModule()->validate(self::viaJson([self::validStroke(['dpr' => 'not-a-number'])]));
    }

    /**
     * The colour pattern ends in "$", which in PCRE also matches before a
     * trailing newline, so "#fc3d03\n" is accepted and stored.
     */
    public function testColourWithTrailingNewlineIsRejected(): void
    {
        $this->expectException(\ValidationException::class);
        $this->makeModule()->validate(self::viaJson([self::validStroke(['color' => "#fc3d03\n"])]));
    }

    // ------------------------------------------------------- shape of the stored points

    public function testPointsAreReturnedAsAPlainListEvenWhenTheClientSendsKeys(): void
    {
        $stroke = self::validStroke();
        $stroke['p'] = ['first' => [1 => 5, 0 => 3], 'second' => [0 => 7, 1 => 9]];

        $result = $this->makeModule()->validate([$stroke]);

        $this->assertSame([[3, 5], [7, 9]], $result[0]['p']);
        $this->assertSame('[[3,5],[7,9]]', json_encode($result[0]['p']));
    }

    public function testAPointWithoutTheKeysZeroAndOneIsRejected(): void
    {
        $stroke = self::validStroke();
        $stroke['p'] = [['x' => 1, 'y' => 2]];

        $this->expectException(\ValidationException::class);
        $this->makeModule()->validate([$stroke]);
    }
}
