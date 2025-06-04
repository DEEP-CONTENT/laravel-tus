<?php

namespace KalynaSolutions\Tus\Events;

use Illuminate\Foundation\Events\Dispatchable;
use KalynaSolutions\Tus\Contracts\TusFileInterface;

class FileUploadFinished
{
    use Dispatchable;

    public function __construct(public TusFileInterface $tusFile)
    {
        //
    }
}
