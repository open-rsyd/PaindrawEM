<?php

namespace OPEN\PainDraw\Tests\Support;

use OPEN\PainDraw\PainDraw;

/**
 * Exposes the module's protected methods and makes the Imagick check
 * controllable, so tests behave the same on every machine.
 */
class TestablePainDraw extends PainDraw
{
    public bool $imagickAvailable = false;

    public function validate(array $data): array
    {
        return $this->validateJsonInput($data);
    }

    public function parseSettings(int|string $projectId, ?string $instrument = null): array
    {
        return $this->parseModuleSettings($projectId, $instrument);
    }

    public function iMagickInstalled(): bool
    {
        return $this->imagickAvailable;
    }
}
