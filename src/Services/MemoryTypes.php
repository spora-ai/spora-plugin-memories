<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

/**
 * Canonical home for memory-domain enums and error-code constants.
 *
 * Lives in its own file so controllers and the HTTP layer can import a
 * single namespace without dragging in the service class — and so the
 * SonarQube `MemoryService` class doesn't keep a dozen constants hanging
 * off it after the service split.
 */
final class MemoryTypes
{
    /** @var list<string> */
    public const DOCUMENT_TYPES = ['plan', 'documentation', 'examples', 'context'];

    public const TYPE_NOT_ALLOWED_CODE   = 'TYPE_NOT_ALLOWED';
    public const REPLACE_NOT_FOUND_CODE  = 'REPLACE_NOT_FOUND';
    public const REPLACE_NOT_UNIQUE_CODE = 'REPLACE_NOT_UNIQUE';
}
