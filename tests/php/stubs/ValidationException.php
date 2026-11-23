<?php

/**
 * PainDraw.php throws and catches \ValidationException in the global namespace.
 *
 * NOTE: this stub makes the tests independent of REDCap. Please verify that a
 * global \ValidationException class really exists in the REDCap versions you
 * support. If it does not, `throw new \ValidationException(...)` in the module
 * fails with "Class not found" instead of being caught by redcap_save_record().
 */
class ValidationException extends \Exception
{
}
