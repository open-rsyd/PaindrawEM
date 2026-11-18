<?php

namespace OPEN\PainDraw\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Shared set-up for the PainDraw tests: clean REDCap stub state, an isolated
 * $_POST and a private error_log file so logged errors can be asserted on.
 */
abstract class PainDrawTestCase extends TestCase
{
    protected string $logFile = '';
    private string|false $previousErrorLog = false;
    private array $previousPost = [];

    protected function setUp(): void
    {
        \REDCap::reset();

        $this->previousPost = $_POST;
        $_POST = [];

        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'paindraw_test_');
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        $_POST = $this->previousPost;
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        if ($this->logFile !== '') {
            @unlink($this->logFile);
        }
    }

    protected function loggedErrors(): string
    {
        clearstatcache();
        return (string) file_get_contents($this->logFile);
    }

    /**
     * @param array<int, array<string, mixed>> $groups sub-settings of group_of_fields
     */
    protected function makeModule(array $groups = []): TestablePainDraw
    {
        $module = new TestablePainDraw();
        $module->fakeSubSettings['group_of_fields'] = $groups;
        return $module;
    }

    /**
     * One configured canvas group, as getSubSettings('group_of_fields') returns it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function canvasGroup(array $overrides = []): array
    {
        return array_replace([
            'instrument' => 'pain_form',
            'canvas_field' => 'pain_draw',
            'image_file' => 42,
            'format_error' => 0,
            'image_file_name' => 'body.png',
            'image_md5' => 'd41d8cd98f00b204e9800998ecf8427e',
            'image_store_field' => null,
            'canvas_width' => '400',
            'canvas_height' => '300',
            'draw_tool' => null,
            'line_width' => null,
            'line_color' => null,
            'transparancy' => null,
            'spray_radius' => null,
            'spray_density' => null,
        ], $overrides);
    }

    /**
     * A stroke exactly as paindraw.js posts it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function validStroke(array $overrides = []): array
    {
        return array_replace([
            'strokeId' => 1,
            'drawInputType' => 'mouse',
            'tool' => 'spray',
            'color' => '#fc3d03',
            'lineWidth' => 3,
            'sprayDensity' => 15,
            'sprayRadius' => 5,
            'alpha' => 0.1,
            'scaleFactor' => 1,
            'dpr' => 1,
            'p' => [[10, 20], [11, 21]],
        ], $overrides);
    }

    /**
     * Pass data through JSON the way it travels from browser to server, so
     * numbers get the same PHP types as in production.
     */
    protected static function viaJson(mixed $data): mixed
    {
        return json_decode(json_encode($data), true);
    }

    /**
     * Run $callback with every PHP warning/notice turned into an exception.
     */
    protected function withWarningsAsExceptions(callable $callback): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $callback();
        } finally {
            restore_error_handler();
        }
    }

    /** Simulate REDCap calling redcap_save_record() for the standard test record. */
    protected function saveRecord(TestablePainDraw $module): void
    {
        $module->redcap_save_record(7, '5', 'pain_form', 3, null, null, null, 1);
    }
}
