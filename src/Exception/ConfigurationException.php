<?php

declare(strict_types=1);

namespace IndexNowKit\Exception;

use InvalidArgumentException;

/**
 * Invalid configuration, attribute or wiring. Raised at construction time, never during a submission.
 */
final class ConfigurationException extends InvalidArgumentException implements IndexNowException {}
