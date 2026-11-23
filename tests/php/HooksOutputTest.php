<?php

namespace OPEN\PainDraw\Tests;

use OPEN\PainDraw\Tests\Support\PainDrawTestCase;
use OPEN\PainDraw\Tests\Support\TestablePainDraw;

/**
 * What redcap_data_entry_form() and redcap_survey_page() print into the page.
 */
class HooksOutputTest extends PainDrawTestCase
{
    private function dataEntryOutput(TestablePainDraw $module, string $instrument = 'pain_form'): string
    {
        ob_start();
        $module->redcap_data_entry_form(7, '5', $instrument);
        return (string) ob_get_clean();
    }

    private function surveyOutput(TestablePainDraw $module, string $instrument = 'pain_form'): string
    {
        ob_start();
        $module->redcap_survey_page(7, '5', $instrument, 3, null, 'SURVEYHASH');
        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function extractConfig(string $html): array
    {
        $this->assertSame(1, preg_match('/const paindraw_cfg = (\{.*?\});<\/script>/s', $html, $m), 'paindraw_cfg not found in output');
        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testDataEntryFormInjectsSettingsAndScript(): void
    {
        $module = $this->makeModule([self::canvasGroup(['canvas_width' => '640', 'canvas_height' => '480'])]);

        $html = $this->dataEntryOutput($module);
        $cfg = $this->extractConfig($html);

        $this->assertFalse($cfg['survey']);
        $this->assertSame('pain_draw', $cfg['canvas_field']);
        $this->assertSame(640, $cfg['canvas_width']);
        $this->assertSame(480, $cfg['canvas_height']);
        $this->assertStringContainsString('doc_id_hash=hash42', $cfg['img_url']);
        $this->assertStringNotContainsString('&s=', $cfg['img_url']);

        $this->assertStringContainsString(
            '<script src="https://example.test/modules/paindraw_v0.3/js/paindraw.js"></script>',
            $html
        );
        $this->assertStringContainsString('paindraw = ExternalModules.Modules.PainDraw', $html);
        $this->assertTrue($module->jsModuleInitialised);
        $this->assertSame(['reset_btn', 'download_btn'], $module->transferredTranslations);
    }

    public function testDataEntryFormPrintsNothingForAnInstrumentWithoutACanvas(): void
    {
        $module = $this->makeModule([self::canvasGroup()]);

        $this->assertSame('', $this->dataEntryOutput($module, 'some_other_form'));
        $this->assertFalse($module->jsModuleInitialised);
    }

    public function testSurveyPageMarksSettingsAsSurveyAndAddsTheSurveyHashToTheImageUrl(): void
    {
        $module = $this->makeModule([self::canvasGroup()]);

        $cfg = $this->extractConfig($this->surveyOutput($module));

        $this->assertTrue($cfg['survey']);
        $this->assertStringEndsWith('&s=SURVEYHASH', $cfg['img_url']);
    }

    public function testSurveyPagePrintsNothingForAnInstrumentWithoutACanvas(): void
    {
        $module = $this->makeModule([self::canvasGroup()]);

        $this->assertSame('', $this->surveyOutput($module, 'some_other_form'));
    }

    public function testUnsupportedImageFormatStillLoadsTheScriptSoItCanShowTheConfigurationError(): void
    {
        $module = $this->makeModule([self::canvasGroup(['format_error' => 1])]);

        $html = $this->dataEntryOutput($module);
        $cfg = $this->extractConfig($html);

        $this->assertFalse($cfg['img_url']);
        $this->assertStringContainsString('js/paindraw.js', $html);
    }
}
