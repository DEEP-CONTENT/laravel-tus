<?php

declare(strict_types=1);

namespace KalynaSolutions\Tus\Contracts;

interface TusFileFactoryInterface
{
    public static function create(?string $id = null, int $size = 0, ?string $rawMetadata = null): TusFileInterface;

    public static function find(string $id): TusFileInterface;
}
