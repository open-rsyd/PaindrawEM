<?php

namespace OPEN\PaindrawEM\Tests\Support;

use OPEN\PaindrawEM\PaindrawEM;

/**
 * Exposes the module's protected methods and makes the Imagick check
 * controllable, so tests behave the same on every machine.
 */
class TestablePainDraw extends PaindrawEM
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

    public function validateDocument(array $document): void
    {
        $this->validateDrawingDocument($document);
    }

    public function iMagickInstalled(): bool
    {
        return $this->imagickAvailable;
    }
}
