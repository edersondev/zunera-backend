<?php

declare(strict_types=1);

namespace App\Exceptions\AccountData;

use RuntimeException;

final class ArchiveRestoreException extends RuntimeException
{
    public function errorCode(): string
    {
        return 'archive_restore_unavailable';
    }
}
