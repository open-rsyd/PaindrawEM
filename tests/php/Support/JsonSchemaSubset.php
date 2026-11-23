<?php

namespace OPEN\PaindrawEM\Tests\Support;

/**
 * A small JSON Schema (draft 2020-12) validator: only the keywords that
 * schema/paindraw_schema_1.0.json uses, so the tests need no extra dependency.
 *
 * It throws a LogicException for any keyword it does not implement. If the schema
 * is extended with a keyword that is not supported here, the conformance tests
 * fail loudly instead of silently ignoring the new rule.
 *
 * Decode the JSON with json_decode($json, false): objects must be stdClass so
 * they can be told apart from arrays.
 */
final class JsonSchemaSubset
{
    /** Keywords that describe the schema but do not constrain the data. */
    private const ANNOTATIONS = ['$schema', '$id', 'title', 'description', '$defs'];

    private object $root;

    public function __construct(object $schema)
    {
        $this->root = $schema;
    }

    public static function fromFile(string $path): self
    {
        return new self(json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @param mixed $instance decoded JSON
     * @return string[] one line per violation, starting with the JSON pointer; empty when valid
     */
    public function validate(mixed $instance): array
    {
        $errors = [];
        $this->check($this->root, $instance, '', $errors);

        return $errors;
    }

    /** @param string[] $errors */
    private function check(object|bool $schema, mixed $value, string $path, array &$errors): void
    {
        $at = $path === '' ? '(document)' : $path;

        if ($schema === true) {
            return;
        }
        if ($schema === false) {
            $errors[] = "$at: no value is allowed here";

            return;
        }

        foreach (get_object_vars($schema) as $keyword => $argument) {
            switch ($keyword) {
                case 'type':
                    $types = (array) $argument;
                    $matches = array_filter($types, fn (string $type): bool => $this->isType($value, $type));
                    if ($matches === []) {
                        $errors[] = "$at: expected " . implode(' or ', $types) . ', got ' . $this->describe($value);
                    }
                    break;

                case 'const':
                    if (!$this->equals($value, $argument)) {
                        $errors[] = "$at: must be " . json_encode($argument) . ', got ' . $this->describe($value);
                    }
                    break;

                case 'enum':
                    if (array_filter($argument, fn ($option): bool => $this->equals($value, $option)) === []) {
                        $errors[] = "$at: must be one of " . json_encode($argument) . ', got ' . $this->describe($value);
                    }
                    break;

                case 'pattern':
                    // The D modifier makes "$" match only at the very end, as in JSON Schema (ECMA 262).
                    if (is_string($value) && preg_match('~' . str_replace('~', '\\~', $argument) . '~uD', $value) !== 1) {
                        $errors[] = "$at: does not match $argument";
                    }
                    break;

                case 'minLength':
                    if (is_string($value) && mb_strlen($value) < $argument) {
                        $errors[] = "$at: shorter than $argument characters";
                    }
                    break;

                case 'minimum':
                    if ($this->isNumber($value) && $value < $argument) {
                        $errors[] = "$at: below $argument";
                    }
                    break;

                case 'maximum':
                    if ($this->isNumber($value) && $value > $argument) {
                        $errors[] = "$at: above $argument";
                    }
                    break;

                case 'exclusiveMinimum':
                    if ($this->isNumber($value) && $value <= $argument) {
                        $errors[] = "$at: not above $argument";
                    }
                    break;

                case 'minItems':
                    if (is_array($value) && count($value) < $argument) {
                        $errors[] = "$at: fewer than $argument items";
                    }
                    break;

                case 'required':
                    if ($value instanceof \stdClass) {
                        foreach ($argument as $name) {
                            if (!property_exists($value, $name)) {
                                $errors[] = "$at: missing required property $name";
                            }
                        }
                    }
                    break;

                case 'properties':
                    if ($value instanceof \stdClass) {
                        foreach (get_object_vars($argument) as $name => $subschema) {
                            if (property_exists($value, $name)) {
                                $this->check($subschema, $value->$name, $path . '/' . $this->escape((string) $name), $errors);
                            }
                        }
                    }
                    break;

                case 'prefixItems':
                    if (is_array($value)) {
                        foreach ($argument as $index => $subschema) {
                            if (array_key_exists($index, $value)) {
                                $this->check($subschema, $value[$index], "$path/$index", $errors);
                            }
                        }
                    }
                    break;

                case 'items':
                    if (is_array($value)) {
                        $skip = isset($schema->prefixItems) ? count($schema->prefixItems) : 0;
                        foreach ($value as $index => $item) {
                            if ($index >= $skip) {
                                $this->check($argument, $item, "$path/$index", $errors);
                            }
                        }
                    }
                    break;

                case '$ref':
                    $this->check($this->resolve($argument), $value, $path, $errors);
                    break;

                case 'if':
                    $inner = [];
                    $this->check($argument, $value, $path, $inner);
                    $branch = $inner === [] ? 'then' : 'else';
                    if (isset($schema->$branch)) {
                        $this->check($schema->$branch, $value, $path, $errors);
                    }
                    break;

                case 'then':
                case 'else':
                    break; // applied by "if"

                default:
                    if (!in_array($keyword, self::ANNOTATIONS, true)) {
                        throw new \LogicException("JSON Schema keyword '$keyword' is not supported by JsonSchemaSubset; extend the validator before relying on it.");
                    }
            }
        }
    }

    private function resolve(string $reference): object|bool
    {
        if (preg_match('~^#/\$defs/([A-Za-z0-9_]+)$~', $reference, $m) !== 1 || !isset($this->root->{'$defs'}->{$m[1]})) {
            throw new \LogicException("Reference '$reference' is not supported by JsonSchemaSubset.");
        }

        return $this->root->{'$defs'}->{$m[1]};
    }

    private function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    private function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            // 1.0 counts as an integer, as in JSON Schema
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'number' => $this->isNumber($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'object' => $value instanceof \stdClass,
            default => throw new \LogicException("JSON type '$type' is not supported by JsonSchemaSubset."),
        };
    }

    private function equals(mixed $a, mixed $b): bool
    {
        if ($this->isNumber($a) && $this->isNumber($b)) {
            return $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $index => $item) {
                if (!array_key_exists($index, $b) || !$this->equals($item, $b[$index])) {
                    return false;
                }
            }

            return true;
        }
        if ($a instanceof \stdClass && $b instanceof \stdClass) {
            return $this->equals(get_object_vars($a), get_object_vars($b));
        }

        return $a === $b;
    }

    private function describe(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: gettype($value);
    }

    private function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
