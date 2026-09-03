<?php

declare(strict_types=1);

namespace IndexNowKit\Exception;

use InvalidArgumentException;

final class ConfigurationException extends InvalidArgumentException implements IndexNowException {}
