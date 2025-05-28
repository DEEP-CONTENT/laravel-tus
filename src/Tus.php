<?php

namespace KalynaSolutions\Tus;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use KalynaSolutions\Tus\Exceptions\FileAppendException;
use KalynaSolutions\Tus\Helpers\TusHeaderBuilder;
use KalynaSolutions\Tus\Helpers\TusUploadMetadataManager;

class Tus
{
    protected const VERSION = '1.0.0';

    protected string $storageDisk;

    public function version(): string
    {
        return static::VERSION;
    }

    public function headers(): TusHeaderBuilder
    {
        return new TusHeaderBuilder(static::VERSION);
    }

    public function metadata(): TusUploadMetadataManager
    {
        return new TusUploadMetadataManager;
    }

    public function isValidChecksum(string $algo, string $hash, string $payload): bool
    {
        if ($hash === hash($algo, $payload)) {
            return true;
        }

        return false;
    }

    public function isUploadExpired(int $lastModified): bool
    {
        if ((int) config('tus.upload_expiration') === 0) {
            return false;
        }

        return Date::createFromTimestamp($lastModified)->addMinutes((int) config('tus.upload_expiration'))->isPast();
    }

    public function maxFileSize(): ?int
    {
        return match (true) {
            (int) config('tus.file_size_limit') > 0 => (int) config('tus.file_size_limit'),
            str_contains(ini_get('post_max_size'), 'M') => (int) ini_get('post_max_size') * 1000000,
            str_contains(ini_get('post_max_size'), 'G') => (int) ini_get('post_max_size') * 1000000000,
            default => null
        };
    }

    public function isInMaxFileSize(int $size): bool
    {
        $limit = $this->maxFileSize();

        if (is_null($limit)) {
            return true;
        }

        return $limit > $size;
    }

    public function extensionIsActive(string $extension): bool
    {
        return in_array($extension, (array) config('tus.extensions'));
    }

    public function id(): string
    {
        while (true) {
            $id = Str::random(40);

            if (! $this->storage()->exists($this->path($id))) {
                break;
            }
        }

        return $id;
    }

    public function path(string $id, ?string $extension = null): string
    {
        return str('')
            ->when(
                value: ! empty($this->getStoragePath()),
                callback: fn (Stringable $str) => $str->append($this->getStoragePath(), '/')
            )
            ->append($id)
            ->when(
                value: $extension,
                callback: fn (Stringable $str) => $str->append('.', $extension)
            )
            ->toString();
    }

    public function storage(): Filesystem
    {
        $this->storageDisk = config('tus.storage_disk');

        return Storage::disk($this->storageDisk);
    }

    public function append(string $path, $data): int
    {
        if ($this->storageDisk === 's3') {
            // S3 doesn't support direct append operations
            $tempFile = tempnam(sys_get_temp_dir(), 'tus_upload_');

            try {
                // If file exists, download it first
                if ($this->storage()->exists($path)) {
                    $existingContent = $this->storage()->get($path);
                    file_put_contents($tempFile, $existingContent);
                }

                // Open and append new data to temp file
                $fp = fopen($tempFile, 'a');
                if ($fp === false) {
                    throw new FileAppendException(message: 'Failed to open temporary file');
                }

                // Write data to temp file
                $bytesWritten = stream_copy_to_stream($data, $fp);
                fclose($fp);

                if ($bytesWritten === false) {
                    throw new FileAppendException(message: 'Failed to write to temporary file');
                }

                // Upload complete file back to S3
                $this->storage()->put($path, file_get_contents($tempFile));

                // Cleanup temp file
                unlink($tempFile);

                return $bytesWritten;
            } catch (\Throwable $e) {
                // Cleanup on errors
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                throw new FileAppendException(message: 'S3 upload error: '.$e->getMessage());
            }
        } else {
            // Original code for local filesystems
            $fullPath = $this->storage()->path($path);
            if (! is_writable($fullPath)) {
                throw new FileAppendException(message: 'File not exists or not writable');
            }

            $fp = fopen($fullPath, 'a');
            if ($fp === false) {
                throw new FileAppendException(message: 'File open error');
            }

            $bytesWritten = stream_copy_to_stream($data, $fp);
            fclose($fp);

            if ($bytesWritten === false) {
                throw new FileAppendException(message: 'File write error');
            }

            return $bytesWritten;
        }
    }

    public function getStoragePath(): string
    {
        $path = config('tus.storage_path');
        if (class_exists(\Spatie\Multitenancy\Models\Tenant::class)) {
            $tenant = \Spatie\Multitenancy\Models\Tenant::current();
            $parts = explode('.', $tenant->domain);
            $path .= '/'.$parts[0] ?? '';
        }

        return $path;
    }
}
