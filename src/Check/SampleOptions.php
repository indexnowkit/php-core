<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

use Closure;

/**
 * The `--sample` and `--sample-class` values of the running `check` command, filled by the command before the
 * checker runs (the checks are built with the graph or the container, the options are known only at run time), plus
 * the sampler that turns a class (and an id) into URLs through the adapter's record loader. One instance per graph;
 * every adapter used to ship its own copy of this holder.
 */
final class SampleOptions
{
    /** @var list<string> */
    public array $urls = [];
    /** @var list<string> */
    public array $classes = [];
    /** @var (Closure(string, string|null): list<string>)|null URLs of a sample of a class: the adapter's ORM sampler */
    public ?Closure $sampler = null;

    public function isEmpty(): bool
    {
        return $this->urls === [] && $this->classes === [];
    }
}
