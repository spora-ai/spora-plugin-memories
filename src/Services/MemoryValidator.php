<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

/**
 * Validation surface for memory payloads. Centralises the document-type
 * enum check and the per-field rules so {@see MemoryCommandService} can
 * stay under Sonar's per-class method-count ceiling.
 *
 * Stateless — instantiated per service via property initialisation; no
 * constructor parameters keeps the existing `new MemoryCommandService()`
 * call sites in the test suite working unchanged.
 */
final class MemoryValidator
{
    /**
     * @param array<string, mixed> $data
     * @throws Exceptions\MemoryValidationException
     */
    public function validate(array $data, bool $isCreation): void
    {
        if ($isCreation) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw new Exceptions\MemoryValidationException('name is required');
            }
            $type = $data['type'] ?? null;
            if (!is_string($type) || $type === '') {
                throw new Exceptions\MemoryValidationException('type is required');
            }
            $this->validateType($type);
            return;
        }
        if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
            throw new Exceptions\MemoryValidationException('name cannot be empty');
        }
        if (array_key_exists('type', $data)) {
            if (!is_string($data['type']) || $data['type'] === '') {
                throw new Exceptions\MemoryValidationException('type cannot be empty');
            }
            $this->validateType($data['type']);
        }
    }

    /**
     * @throws Exceptions\MemoryValidationException
     */
    public function validateType(string $type): void
    {
        if (!in_array($type, MemoryTypes::DOCUMENT_TYPES, true)) {
            throw new Exceptions\MemoryValidationException(
                sprintf(
                    "type '%s' is not one of: %s",
                    $type,
                    implode(', ', MemoryTypes::DOCUMENT_TYPES),
                ),
            );
        }
    }
}
