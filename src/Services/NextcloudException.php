<?php

namespace Nawasara\Workspace\Services;

use RuntimeException;

/**
 * A Nextcloud admin call failed. Carries the HTTP status and the OCS status
 * code, because some answers are expected and handled by the caller (404 =
 * no such user, OCS 102 = group exists) while everything else is an error.
 */
class NextcloudException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $ocsCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
