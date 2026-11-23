<?php

/**
 * Stand-in for REDCap's Files helper class.
 */
final class Files
{
    public static function docIdHash($docId)
    {
        return 'hash' . $docId;
    }
}
