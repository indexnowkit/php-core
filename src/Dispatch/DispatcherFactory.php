<?php

declare(strict_types=1);

namespace IndexNowKit\Dispatch;

use Closure;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\SubmitterInterface;
use Psr\Log\LoggerInterface;

/**
 * The dispatcher an adapter wires from `dispatch`: nothing when IndexNow is disabled or the mode is `none`,
 * inline delivery for `sync`, the adapter's queue for anything else.
 */
final class DispatcherFactory
{
    public const SYNC = 'sync';
    public const NONE = 'none';

    private function __construct() {}

    /**
     * @param (Closure(): DispatcherInterface)|null $queue builds the adapter's queue dispatcher for a mode that is neither sync nor none
     *
     * @throws ConfigurationException when the mode needs a queue and the adapter gave none
     */
    public static function fromConfig(Config $config, SubmitterInterface $submitter, LoggerInterface $logger, ?Closure $queue = null): DispatcherInterface
    {
        if (!$config->enabled || $config->dispatch === self::NONE) {
            return new NullDispatcher();
        }
        if ($config->dispatch === self::SYNC) {
            return new SyncDispatcher($submitter, $logger, $config->logUrls);
        }
        if ($queue === null) {
            throw new ConfigurationException(\sprintf('"dispatch" "%s" needs a queue dispatcher, which this adapter does not provide; use "sync" or "none".', $config->dispatch));
        }

        return $queue();
    }
}
