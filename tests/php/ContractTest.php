<?php

namespace OPEN\PaindrawEM\Tests;

use OPEN\PaindrawEM\Tests\Support\PainDrawTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PHP half of the contract between paindraw.js and the server.
 *
 * tests/fixtures/stroke-contract.json and tests/fixtures/strokes/*.json are
 * shared with the JavaScript tests (tests/js/paindraw.contract.test.js).
 */
class ContractTest extends PainDrawTestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    /** @return mixed */
    private static function loadJson(string $relativePath)
    {
        $json = file_get_contents(self::FIXTURES . '/' . $relativePath);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function validFixtureProvider(): array
    {
        $cases = [];
        foreach (glob(self::FIXTURES . '/strokes/valid_*.json') as $path) {
            $cases[basename($path)] = ['strokes/' . basename($path)];
        }
        return $cases;
    }

    #[DataProvider('validFixtureProvider')]
    public function testServerAcceptsEveryValidFixture(string $fixture): void
    {
        $strokes = self::loadJson($fixture);

        $result = $this->makeModule()->validate($strokes);

        $this->assertCount(count($strokes), $result);
    }

    #[DataProvider('validFixtureProvider')]
    public function testValidatedStrokesHaveExactlyTheContractKeys(string $fixture): void
    {
        $contract = self::loadJson('stroke-contract.json');

        foreach ($this->makeModule()->validate(self::loadJson($fixture)) as $stroke) {
            $keys = array_keys($stroke);
            $expected = $contract['strokeKeys'];
            sort($keys);
            sort($expected);
            $this->assertSame($expected, $keys);
        }
    }

    public static function invalidFixtureProvider(): array
    {
        $cases = [];
        foreach (self::loadJson('strokes/invalid_strokes.json') as $case) {
            $cases[$case['name']] = [$case['strokes']];
        }
        return $cases;
    }

    #[DataProvider('invalidFixtureProvider')]
    public function testServerRejectsEveryInvalidFixture(array $strokes): void
    {
        $this->expectException(\ValidationException::class);

        $this->makeModule()->validate($strokes);
    }
}
