<?php

declare(strict_types=1);

namespace IndexNowKit\Exception;

use InvalidArgumentException;

/**
 * A URL that cannot be submitted. Submitter catches it and drops the URL with a warning.
 */
final class InvalidUrlException extends InvalidArgumentException implements IndexNowException {}
