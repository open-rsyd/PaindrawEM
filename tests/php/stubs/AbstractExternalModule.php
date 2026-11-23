<?php

namespace ExternalModules;

/**
 * Minimal stand-in for REDCap's AbstractExternalModule.
 *
 * Only the methods PaindrawEM.php uses are implemented. Public "fake*" and
 * "saved*" properties let tests feed settings in and read results out.
 */
abstract class AbstractExternalModule
{
    /** @var array<string, array<int, array<string, mixed>>> sub-settings keyed by setting key */
    public array $fakeSubSettings = [];

    /** @var array<string, array<int, mixed>> value returned by getProjectSettings() */
    public array $fakeProjectSettings = [];

    /** @var array<string, mixed>|null what the module passed to setProjectSettings() */
    public ?array $savedProjectSettings = null;

    /** @var string[] translation keys passed to tt_transferToJavascriptModuleObject() */
    public array $transferredTranslations = [];

    public bool $jsModuleInitialised = false;

    /** Name of the module directory, from which the module takes its version. */
    public string $directoryName = 'paindraw_v1.0.0';

    public object $framework;

    public function __construct()
    {
        $this->framework = new class {
            public function getJavascriptModuleObjectName(): string
            {
                return 'ExternalModules.Modules.PainDraw';
            }
        };
    }

    public function getSubSettings($key)
    {
        return $this->fakeSubSettings[$key] ?? [];
    }

    public function getProjectSettings()
    {
        return $this->fakeProjectSettings;
    }

    public function setProjectSettings($settings)
    {
        $this->savedProjectSettings = $settings;
    }

    public function getUrl($path)
    {
        return 'https://example.test/modules/' . $this->directoryName . '/' . $path;
    }

    public function getModuleDirectoryName()
    {
        return $this->directoryName;
    }

    public function initializeJavascriptModuleObject()
    {
        $this->jsModuleInitialised = true;
    }

    public function tt_transferToJavascriptModuleObject($keys)
    {
        $this->transferredTranslations = $keys;
    }

    /**
     * Mirrors the framework: accepts integers and integer-like strings.
     */
    public function requireInteger($value)
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        throw new \Exception('Expected an integer but received: ' . var_export($value, true));
    }
}
