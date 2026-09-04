<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

/**
 * Backward-compatible constants shim for the v2 service split.
 *
 * The v2 split moved the actual service implementations to
 * {@see MemoryQueryService} and {@see MemoryCommandService}, with the
 * narrow {@see MemoryQueryInterface} / {@see MemoryCommandInterface}
 * contracts and the combined {@see MemoryServiceInterface}. The
 * domain enums and error-code constants were lifted into
 * {@see MemoryTypes} so callers don't have to qualify a service class.
 *
 * This class remains as a one-release deprecation alias — external
 * code that still references `MemoryService::TYPE_NOT_ALLOWED_CODE` (or
 * any of the other constants) keeps compiling. The class is final and
 * carries no methods so it doesn't trigger the per-class method-count
 * ceiling; the actual work has moved out to the two new services.
 *
 * @deprecated since 0.2.0 — use {@see MemoryTypes} for constants,
 *             {@see MemoryQueryService} / {@see MemoryCommandService}
 *             for the implementations. Will be removed in 0.3.0.
 */
final class MemoryService
{
    /** @var list<string> */
    public const DOCUMENT_TYPES         = MemoryTypes::DOCUMENT_TYPES;
    public const TYPE_NOT_ALLOWED_CODE  = MemoryTypes::TYPE_NOT_ALLOWED_CODE;
    public const REPLACE_NOT_FOUND_CODE = MemoryTypes::REPLACE_NOT_FOUND_CODE;
    public const REPLACE_NOT_UNIQUE_CODE = MemoryTypes::REPLACE_NOT_UNIQUE_CODE;
}
