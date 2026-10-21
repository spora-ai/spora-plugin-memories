<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

final class MemoryTypes
{
    /** @var list<string> */
    public const DOCUMENT_TYPES = ['plan', 'documentation', 'examples', 'context'];

    public const TYPE_NOT_ALLOWED_CODE   = 'TYPE_NOT_ALLOWED';
    public const REPLACE_NOT_FOUND_CODE  = 'REPLACE_NOT_FOUND';
    public const REPLACE_NOT_UNIQUE_CODE = 'REPLACE_NOT_UNIQUE';
}
