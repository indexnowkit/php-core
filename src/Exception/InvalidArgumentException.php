<?php

declare(strict_types=1);

namespace IndexNowKit\Exception;

/**
 * Programming error at a call site (empty batch, key length out of range). Never thrown for remote failures.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements IndexNowException {}
