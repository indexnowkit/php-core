<?php

declare(strict_types=1);

namespace IndexNowKit\Http\Exception;

use IndexNowKit\Exception\IndexNowException;
use RuntimeException;

final class TransportException extends RuntimeException implements IndexNowException {}
