<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

/**
 * What a `check` command runs. {@see Checker} is the shipped implementation (configuration, key files, wiring
 * hints, optional live probe, plus every registered {@see CheckInterface}).
 */
interface CheckerInterface
{
    /**
     * @param bool        $liveProbe POST a page of every host to every endpoint (a real request)
     * @param string|null $onlyHost  check this host only
     * @param string|null $probeUrl  page to probe with (default: the host root)
     */
    public function run(bool $liveProbe = false, ?string $onlyHost = null, ?string $probeUrl = null): CheckReport;
}
