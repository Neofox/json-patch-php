<?php

/*
.---------------------------------------------------------------------------.
|  Software: JSON-Patch PHP library                                         |
|   Version: 0.0.2                                                          |
|      Site: https://github.com/mikemccabe/json-patch-php                   |
| ------------------------------------------------------------------------- |
|   License: LGPL-3.0
'---------------------------------------------------------------------------'

Produce and apply JSON-patch objects.

Implements IETF JSON-patch (RFC 6902) and JSON-pointer (RFC 6901):

http://tools.ietf.org/html/rfc6902

http://tools.ietf.org/html/rfc6901

Entry points
------------

- get($doc, $pointer) - get a value from a JSON document
- diff($src, $dst) - return patches to create $dst from $src
- patch($doc, $patches) - apply patches to $doc and return result

Arguments are PHP arrays, i.e. the output of
json_decode($json_string, 1).

(Note that you MUST pass 1 as the second argument to json_decode to
get an array.  This library does not work with stdClass objects.)

All structures are implemented directly as PHP arrays.  An array is
considered to be 'associative' (e.g. like a JSON 'object') if it
contains at least one non-numeric key.

Because of this, empty arrays ([]) and empty objects ({}) compare the
same, and (for instance) an 'add' of a string key to an empty array
will succeed in this implementation where it might fail in others.

$simplexml_mode is provided to help with working with arrays produced
from XML in the style of simplexml - e.g. repeated XML elements are
expressed as arrays.  When $simplexml_mode is enabled, leaves with
scalar values are implicitly treated as length-1 arrays, so this test
will succeed:

    { "comment": "basic simplexml array promotion",
      "doc": { "foo":1 },
      "patch": [ { "op":"add", "path":"/foo/1", "value":2 } ],
      "expected": { "foo":[1, 2] } },

Also, when $simplexml_mode is true, 1-length arrays are converted to
scalars on return from patch().

*/

namespace mikemccabe\JsonPatch;

class JsonPatch
{
    /**
     * Follow a json-pointer address into a JSON document and return
     * the designated leaf value
     *
     * @param array $doc
     * @param string $pointer
     * @param bool $simplexml_mode
     *
     * @return string|array
     *
     * @throws JsonPatchException
     */
    public static function get(array $doc, string $pointer, bool $simplexml_mode = false)
    {
        $parts = self::decompose_pointer($pointer);

        return self::get_helper($doc, $pointer, $parts, $simplexml_mode);
    }


    /**
     * Compute a list of json-patch structures representing the diff
     * between $src and $dst
     *
     * @param array|int|string $src
     * @param array|string|int $dst
     *
     * @return array
     */
    public static function diff($src, $dst): array
    {
        return array_reverse(self::diff_values('', $src, $dst));
    }

    /**
     * Compute a new document from the supplied $doc and $patches.
     *
     * @param array|string|int $doc
     * @param      $patches
     * @param bool $simplexml_mode
     *
     * @return array|string
     *
     * @throws JsonPatchException
     */
    public static function patch($doc, array $patches, $simplexml_mode = false)
    {
        // accept singleton patches
        if (\count($patches) !== 0 && !isset($patches[0])) {
            $patches = [$patches];
        }

        foreach ($patches as $patch) {
            $op = $patch['op'];
            $path = $patch['path'];
            $value = '';

            if (empty($op)) {
                throw new JsonPatchException("'op' missing in " . json_encode($patch));
            }
            if (!\is_string($path)) {
                throw new JsonPatchException("'path' missing in " . json_encode($patch));
            }
            if (!\in_array($op, ['add', 'remove', 'replace', 'move', 'copy', 'test', 'append'], true)) {
                throw new JsonPatchException("Unrecognized op '$op' in " . json_encode($patch));
            }

            $parts = self::decompose_pointer($path);
            if (\in_array($op, ['test', 'add', 'replace', 'append'], true)) {
                if (!array_key_exists('value', $patch)) {
                    throw new JsonPatchException("'value' missing in " . json_encode($patch));
                }
                $value = $patch['value'];
            }
            if (\in_array($op, ['move', 'copy'], true)) {
                if (!array_key_exists('from', $patch)) {
                    throw new JsonPatchException("'from' missing in " . json_encode($patch));
                }
                $from_path = $patch['from'];
                $from_parts = self::decompose_pointer($from_path);
            }

            if ($op === 'add') {
                $doc = self::do_op($doc, $op, $path, $parts, $value, $simplexml_mode);
            }

            switch ($op) {
                case 'append':
                    $doc = self::do_op($doc, $op, $path, $parts, $value, $simplexml_mode);
                    break;
                case 'replace':
                    $doc = self::do_op($doc, $op, $path, $parts, $value, $simplexml_mode);
                    break;
                case 'remove':
                    $doc = self::do_op($doc, $op, $path, $parts, null, $simplexml_mode);
                    break;
                case 'test':
                    self::test($doc, $path, $parts, $value, $simplexml_mode);
                    break;
                case 'copy':
                    $value = self::get_helper($doc, $from_path, $from_parts, $simplexml_mode);
                    $doc = self::do_op($doc, 'add', $path, $parts, $value, $simplexml_mode);
                    break;
                case 'move':
                    $value = self::get_helper($doc, $from_path, $from_parts, $simplexml_mode);
                    $doc = self::do_op($doc, 'remove', $from_path, $from_parts, null, $simplexml_mode);
                    $doc = self::do_op($doc, 'add', $path, $parts, $value, $simplexml_mode);
                    break;
                default:
                    if ($op !== 'add') {
                        throw new JsonPatchException("Invalid operation $op.");
                    }
            }
        }

        if ($simplexml_mode) {
            $doc = self::re_singletize($doc);
        }

        return $doc;
    }


    public static function compose_pointer(array $parts): string
    {
        $result = '';
        foreach ($parts as $part) {
            $part = self::escape_pointer_part($part);
            $result .= "/$part";
        }

        return $result;
    }


    /**
     * @param string|array $part
     *
     * @return string|array
     */
    public static function escape_pointer_part($part)
    {
        return str_replace(['~', '/'], ['~0', '~1'], $part);
    }


    // Private functions follow


    /**
     * Walk through the doc and turn every 1-length array into a
     * singleton value.  This follows SimpleXML behavior.
     *
     * @param array|string|int $doc
     *
     * @return array|string|int
     */
    private static function re_singletize($doc)
    {
        if (!\is_array($doc)) {
            return $doc;
        }

        if (array_key_exists(0, $doc) && \count($doc) === 1) {
            return self::re_singletize($doc[0]);
        }

        $result = [];
        foreach (array_keys($doc) as $key) {
            $result[$key] = self::re_singletize($doc[$key]);
        }

        return $result;
    }


    /**
     * @param $pointer
     *
     * @return array
     *
     * @throws JsonPatchException
     */
    private static function decompose_pointer($pointer): array
    {
        $parts = explode('/', $pointer);
        if (array_shift($parts) !== '') {
            throw new JsonPatchException("path must start with / in $pointer");
        }
        foreach ($parts as $i => $iValue) {
            $parts[$i] = str_replace('~1', '/', $parts[$i]);
            $parts[$i] = str_replace('~0', '~', $parts[$i]);
        }

        return $parts;
    }

    /**
     * only 0 or counting number; '1e0' is excluded.
     *
     * @param $part
     *
     * @return bool
     */
    private static function is_index($part): bool
    {
        return 1 === preg_match('/^(0|[1-9][0-9]*)$/', $part);
    }

    /**
     * Dispatch to a recursive diff_assoc or diff_array call if needed,
     * or emit a patch to replace the current value.
     *
     * @param string $path
     * @param string|array $value
     * @param string|array $other
     *
     * @return array
     */
    private static function diff_values(string $path, $value, $other): array
    {
        // manually handle the {}-looks-like-[] case, when other is associative
        if ((!$value || !$other) && (self::is_associative($value) || self::is_associative($other))) {
            return self::diff_assoc($path, $value, $other);
        }

        if (self::is_associative($value) && self::is_associative($other)) {
            return self::diff_assoc($path, $value, $other);
        }

        if (\is_array($value) && \is_array($other) && !self::is_associative($value)) {
            return self::diff_array($path, $value, $other);
        }

        if ($value !== $other) {
            return [['op' => 'replace', 'path' => "$path", 'value' => $other]];
        }

        return [];
    }

    /**
     * Walk associative arrays $src and $dst, returning a list of patches
     *
     * @param string $path
     * @param array $src
     * @param array $dst
     *
     * @return array
     */
    private static function diff_assoc(string $path, array $src, array $dst): array
    {
        $result = [];
        if (\count($src) === 0 && \count($dst) !== 0) {
            $result[] = ['op' => 'replace', 'path' => "$path", 'value' => $dst];
        } else {
            foreach (array_keys($src) as $key) {
                $ekey = self::escape_pointer_part($key);
                if (!array_key_exists($key, $dst)) {
                    $result[] = ['op' => 'remove', 'path' => "$path/$ekey"];
                } else {
                    $result = array_merge($result, self::diff_values("$path/$ekey", $src[$key], $dst[$key]));
                }
            }
            foreach (array_keys($dst) as $key) {
                if (!array_key_exists($key, $src)) {
                    $ekey = self::escape_pointer_part($key);
                    $result[] = ['op' => 'add', 'path' => "$path/$ekey", 'value' => $dst[$key]];
                }
            }
        }

        return $result;
    }

    /**
     * Walk simple arrays $src and $dst, returning a list of patches
     *
     * @param string $path
     * @param array $src
     * @param array $dst
     *
     * @return array
     */
    private static function diff_array(string $path, array $src, array $dst): array
    {
        $result = [];
        $lsrc = \count($src);
        $ldst = \count($dst);
        $max = ($lsrc > $ldst) ? $lsrc : $ldst;

        // Walk backwards through arrays, starting with longest
        $i = $max - 1;
        while ($i >= 0) // equivalent for loop didn't work?
        {
            if ($i < $lsrc && $i < $ldst &&
                array_key_exists($i, $src) && array_key_exists($i, $dst)) {
                $result = array_merge($result, self::diff_values("$path/$i", $src[$i], $dst[$i]));
            } else if ($i < $ldst && array_key_exists($i, $dst)) {
                $result[] = ['op' => 'add', 'path' => "$path/$i", 'value' => $dst[$i]];
            } else if ($i < $lsrc && !array_key_exists($i, $dst)) {
                $result[] = ['op' => 'remove', 'path' => "$path/$i"];
            }
            $i--;
        }

        return $result;
    }

    /**
     * Implements the 'test' op
     *
     * @param $doc
     * @param string $path
     * @param $parts
     * @param $value
     * @param $simplexml_mode
     *
     * @throws JsonPatchException
     */
    private static function test($doc, string $path, $parts, $value, $simplexml_mode)
    {
        $found = self::get_helper($doc, $path, $parts, $simplexml_mode);

        if (!self::considered_equal($found, $value)) {
            throw new JsonPatchException("test target value different - expected " . json_encode($value) . ", found " . json_encode($found));
        }
    }

    /**
     * Helper for get() and 'copy', 'move', 'test' ops - get a value from a doc.
     *
     * @param $doc
     * @param string $path
     * @param $parts
     * @param $simplexml_mode
     *
     * @return mixed
     *
     * @throws JsonPatchException
     */
    private static function get_helper($doc, string $path, $parts, $simplexml_mode)
    {
        if (\count($parts) === 0) {
            return $doc;
        }

        $part = array_shift($parts);
        if (!\is_array($doc) || !array_key_exists($part, $doc)) {
            throw new JsonPatchException("Path '$path' not found");
        }
        if ($simplexml_mode && \count($parts) > 0 && $parts[0] === '0' && self::is_associative($doc) && !(\is_array($doc[$part]) && !self::is_associative($doc[$part]))) {
            return self::get_helper([$doc[$part]], $path, $parts, $simplexml_mode);
        }

        return self::get_helper($doc[$part], $path, $parts, $simplexml_mode);
    }


    // Test whether a php array looks 'associative' - does it have
    // any non-numeric keys?
    //
    // note: is_associative(array()) === false
    private static function is_associative($a): bool
    {
        if (!\is_array($a)) {
            return false;
        }
        foreach (array_keys($a) as $key) {
            if (\is_string($key)) {
                return true;
            }
        }
        // Also treat php gappy arrays as associative.
        // (e.g. {"0":"a", "2":"c"})
        $len = \count($a);
        for ($i = 0; $i < $len; $i++) {
            if (!array_key_exists($i, $a)) {
                return true;
            }
        }

        return false;
    }

    // Recursively sort array keys

    /**
     * @param int|string|array $a
     *
     * @return array|int|string
     */
    private static function rksort($a)
    {
        if (!\is_array($a)) {
            return $a;
        }
        foreach (array_keys($a) as $key) {
            $a[$key] = self::rksort($a[$key]);
        }
        // SORT_STRING seems required, as otherwise numeric indices
        // (e.g. "4") aren't sorted.
        ksort($a, SORT_STRING);

        return $a;
    }


    // Per http://tools.ietf.org/html/rfc6902#section-4.6
    public static function considered_equal($a1, $a2): bool
    {
        return json_encode(self::rksort($a1)) === json_encode(self::rksort($a2));
    }


    /**
     * Apply a single op to modify the given document.
     * As php arrays are not passed by reference, this function works
     * recursively, rebuilding complete subarrays that need changing;
     * the revised subarray is changed in the parent array before returning it.
     *
     * @param $doc
     * @param $op
     * @param string $path
     * @param $parts
     * @param string|array $value
     * @param $simplexml_mode
     *
     * @return array|string
     *
     * @throws JsonPatchException
     */
    private static function do_op($doc, $op, string $path, $parts, $value, $simplexml_mode)
    {
        // Special-case toplevel
        if (\count($parts) === 0) {
            if ($op === 'add' || $op === 'replace') {
                return $value;
            }

            if ($op === 'remove') {
                throw new JsonPatchException("Can't remove whole document");
            }

            throw new JsonPatchException("'$op' can't operate on whole document");
        }

        $part = array_shift($parts);

        // recur until we get to the target
        if (\count($parts) > 0) {
            if (!array_key_exists($part, $doc)) {
                throw new JsonPatchException("Path '$path' not found");
            }
            // recur, adding resulting sub-doc into doc returned to caller

            // special case for simplexml-style behavior - make singleton
            // scalar leaves look like 1-length arrays
            if ($simplexml_mode && \count($parts) > 0 && ($parts[0] === '0' || $parts[0] === '1' || $parts[0] === '-')
                && self::is_associative($doc) && !(\is_array($doc[$part]) && !self::is_associative($doc[$part]))) {
                $doc[$part] = self::do_op([$doc[$part]], $op, $path, $parts, $value, $simplexml_mode);
            } else {
                $doc[$part] = self::do_op($doc[$part], $op, $path, $parts, $value, $simplexml_mode);
            }

            return $doc;
        }

        // at target
        if (!\is_array($doc)) {
            throw new JsonPatchException('Target must be array or associative array');
        }

        if (!self::is_associative($doc)) // N.B. returns false for empty arrays
        {
            if (\count($doc) && !self::is_index($part) && !($part === '-' && ($op === 'add' || $op === 'append'))) {
                throw new JsonPatchException("Non-array key '$part' used on array");
            }

            // check range, if numeric
            if (self::is_index($part) && ($part < 0 || (($op === 'remove' && $part >= \count($doc)) || ($op !== 'remove' && $part > \count($doc))))) {
                throw new JsonPatchException("Can't operate outside of array bounds.");
            }
        }

        if ($op === 'add' || $op === 'append') {
            if (!self::is_associative($doc) && (self::is_index($part) || $part === '-')) {
                // If index is '-', use array length
                $index = ($part === '-') ? \count($doc) : $part;
                if ($op === 'append') {
                    array_splice($doc, $index, 0, $value);
                } else {
                    array_splice($doc, $index, 0, [$value]);
                }
            } else {
                $doc[$part] = $value;
            }
        } else if ($op === 'replace') {
            if (!self::is_associative($doc) && self::is_index($part)) {
                array_splice($doc, $part, 1, [$value]);
            } else {
                if (!array_key_exists($part, $doc)) {
                    throw new JsonPatchException("replace target '$path' not set");
                }
                $doc[$part] = $value;
            }
        } else if ($op === 'remove') {
            if (!self::is_associative($doc) && self::is_index($part)) {
                array_splice($doc, $part, 1);
            } else {
                if (!array_key_exists($part, $doc)) {
                    throw new JsonPatchException("remove target '$path' not set");
                }
                unset($doc[$part]);
            }
        }

        return $doc;
    }
}
