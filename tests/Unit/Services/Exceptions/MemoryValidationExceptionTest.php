<?php

declare(strict_types=1);

use Spora\Plugins\Memories\Services\Exceptions\MemoryValidationException;

it('extends RuntimeException so callers can catch the parent type', function (): void {
    expect(new MemoryValidationException('boom'))->toBeInstanceOf(RuntimeException::class);
});

it('preserves the message passed at construction', function (): void {
    $exception = new MemoryValidationException('name is required');

    expect($exception->getMessage())->toBe('name is required');
});
