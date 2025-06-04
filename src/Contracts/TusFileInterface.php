<?php

declare(strict_types=1);

namespace KalynaSolutions\Tus\Contracts;

interface TusFileInterface
{
    public function getId(): string;

    public function getPath(): string;

    public function getDisk(): string;

    public function getMetadata(): array;
}
