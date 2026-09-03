<?php

declare(strict_types=1);

namespace IndexNowKit\Exception;

/**
 * A URL that cannot be submitted. Submitter catches it and reports the URL as skipped (Reason::InvalidUrl).
 */
final class InvalidUrlException extends InvalidArgumentException implements IndexNowException {}
