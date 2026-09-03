<?php

declare(strict_types=1);

namespace IndexNowKit\Exception;

use InvalidArgumentException;

final class InvalidUrlException extends InvalidArgumentException implements IndexNowException {}
