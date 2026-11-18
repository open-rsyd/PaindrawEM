<?php

/**
 * Recording stand-in for the static REDCap API used by the module.
 *
 * storeFile() reads the file immediately, because the module hands over a
 * tmpfile() that disappears as soon as the calling method returns.
 */
class REDCap
{
    /** @var array<int, array{docId:int, pid:mixed, name:string, content:string}> */
    public static array $stored = [];

    /** @var array<int, array{docId:mixed, pid:mixed, record:mixed, field:mixed, event:mixed, instance:mixed}> */
    public static array $linked = [];

    /** @var array<int, array{0:?string, 1:?string, 2:?string}> docId => [mime, name, content] */
    public static array $files = [];

    public static int $nextDocId = 100;

    /** When true storeFile() fails the way REDCap does: it returns 0. */
    public static bool $failStore = false;

    /** Return value of addFileToField(). */
    public static bool $linkResult = true;

    public static function reset(): void
    {
        self::$stored = [];
        self::$linked = [];
        self::$files = [];
        self::$nextDocId = 100;
        self::$failStore = false;
        self::$linkResult = true;
    }

    /** Test helper: register a file that getFile() can return. */
    public static function addFakeFile(int $docId, string $mime, string $name, string $content): void
    {
        self::$files[$docId] = [$mime, $name, $content];
    }

    public static function storeFile($path, $projectId, $fileName)
    {
        if (self::$failStore) {
            return 0;
        }
        $docId = self::$nextDocId++;
        self::$stored[] = [
            'docId' => $docId,
            'pid' => $projectId,
            'name' => $fileName,
            'content' => (string) file_get_contents($path),
        ];
        return $docId;
    }

    public static function addFileToField($docId, $projectId, $record, $field, $event, $instance)
    {
        self::$linked[] = [
            'docId' => $docId,
            'pid' => $projectId,
            'record' => $record,
            'field' => $field,
            'event' => $event,
            'instance' => $instance,
        ];
        return self::$linkResult;
    }

    public static function getFile($docId)
    {
        return self::$files[$docId] ?? [null, null, null];
    }
}
