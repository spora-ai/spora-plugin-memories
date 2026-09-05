<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

use Spora\Services\Text\Utf8Sanitizer;

/**
 * String-mutation surface for memory content. Owns the single-occurrence
 * substring replacement rule and the per-field UTF-8 scrub pass so
 * {@see MemoryCommandService} can stay under Sonar's per-class
 * method-count ceiling.
 *
 * Stateless — instantiated per service via property initialisation; no
 * constructor parameters keeps the existing `new MemoryCommandService()`
 * call sites in the test suite working unchanged.
 */
final class MemoryContentEditor
{
    /**
     * Single-occurrence substring replacement on memory content.
     *
     * Throws {@see Exceptions\MemoryValidationException} with a message
     * naming the actual occurrence count when `find` matches 0 or >1
     * substrings (operators must supply a unique anchor). On a single
     * match the result is run through `Utf8Sanitizer::scrubString()`
     * before being returned so the persisted bytes stay valid UTF-8.
     *
     * @throws Exceptions\MemoryValidationException
     */
    public function replaceInMemoryContent(string $current, string $find, string $newText): string
    {
        $count = mb_substr_count($current, $find);
        if ($count === 0) {
            throw new Exceptions\MemoryValidationException("find matches 0 occurrences.");
        }
        if ($count > 1) {
            throw new Exceptions\MemoryValidationException(
                "find matches {$count} > 1 occurrences; provide a unique substring.",
            );
        }

        return Utf8Sanitizer::scrubString(str_replace($find, $newText, $current));
    }

    /**
     * Run {@see Utf8Sanitizer::scrubString()} over each named string
     * field in `$data` when present. Non-string or missing fields are
     * skipped — callers don't need to pre-check the field type.
     *
     * @param array<string, mixed> $data
     * @param string ...$fields
     * @return array<string, mixed>
     */
    public function scrubStringFields(array $data, string ...$fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = Utf8Sanitizer::scrubString($data[$field]);
            }
        }
        return $data;
    }
}
