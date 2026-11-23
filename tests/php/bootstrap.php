<?php

/**
 * PHPUnit bootstrap for the PainDraw external module.
 *
 * The module extends REDCap's AbstractExternalModule and calls the static
 * REDCap and Files classes. None of that is available outside a REDCap
 * installation, so lightweight stand-ins are loaded from ./stubs. They record
 * what the module does, which is what the unit tests assert on.
 */

// REDCap defines this constant; the real value depends on the installed version.
if (!defined('APP_PATH_WEBROOT')) {
    define('APP_PATH_WEBROOT', '/redcap/redcap_v99.0.0/');
}

require_once __DIR__ . '/stubs/ValidationException.php';
require_once __DIR__ . '/stubs/AbstractExternalModule.php';
require_once __DIR__ . '/stubs/REDCap.php';
require_once __DIR__ . '/stubs/Files.php';

require_once __DIR__ . '/../../PainDraw.php';

require_once __DIR__ . '/Support/TestablePainDraw.php';
require_once __DIR__ . '/Support/TestImages.php';
require_once __DIR__ . '/Support/JsonSchemaSubset.php';
require_once __DIR__ . '/Support/PainDrawTestCase.php';
