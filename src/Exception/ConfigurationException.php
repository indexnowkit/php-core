<?php

declare(strict_types=1);

namespace IndexNowKit\Exception;

use InvalidArgumentException;

/**
 * Invalid configuration, attribute or wiring. Thrown when the invalid piece is used: at construction for Config and
 * the attribute, at resolution time for resolvers and locators. ORM hooks route resolution through
 * GuardedUrlResolver / ObjectChangeHandler, which log it instead of throwing.
 */
final class ConfigurationException extends InvalidArgumentException implements IndexNowException {}
