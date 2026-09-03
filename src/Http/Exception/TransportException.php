<?php

declare(strict_types=1);

namespace IndexNowKit\Http\Exception;

use IndexNowKit\Exception\IndexNowException;
use RuntimeException;

/**
 * Network-level failure (connection, timeout, oversized body). Never raised for HTTP status codes.
 */
final class TransportException extends RuntimeException implements IndexNowException {}
