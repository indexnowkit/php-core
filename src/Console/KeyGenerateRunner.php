<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Key\KeyGenerator;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Body of `indexnow:key:generate`: prints a fresh key, or writes `INDEXNOW_KEY=<key>` to an env file. Writing is
 * idempotent: an existing line is kept unless `--force` rotates it.
 */
final class KeyGenerateRunner
{
    public function __construct(private readonly Vocabulary $words = new Vocabulary()) {}

    /**
     * @param int         $length  key length (8-128)
     * @param bool        $hex     hex alphabet; false = the full alphanumeric alphabet
     * @param string|null $envFile file to write `INDEXNOW_KEY=` to; null = print only
     * @param bool        $force   replace an existing INDEXNOW_KEY line (key rotation)
     *
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, int $length = 32, bool $hex = true, ?string $envFile = null, bool $force = false): int
    {
        $key = KeyGenerator::generate($length, $hex);

        if ($envFile === null) {
            $io->writeln($key);
            $io->newLine();
            $io->text(['Add to your environment:', '  INDEXNOW_KEY=' . $key, \sprintf('Then run: %s indexnow:check', $this->words->cli)]);

            return ExitCode::SUCCESS;
        }

        $contents = is_file($envFile) ? (string) file_get_contents($envFile) : '';
        $line = 'INDEXNOW_KEY=' . $key;
        if (preg_match('/^\s*INDEXNOW_KEY\s*=/m', $contents) === 1) {
            if (!$force) {
                $io->writeln(\sprintf('<info>%s already defines INDEXNOW_KEY, nothing to do (use --force to rotate the key).</info>', $envFile));

                return ExitCode::SUCCESS;
            }
            $contents = (string) preg_replace('/^(\s*)INDEXNOW_KEY\s*=.*$/m', '$1' . $line, $contents, 1);
            $io->warning('Rotating the key: submissions fail with 403 until the new key file is reachable (CDN caches!). Run indexnow:check afterwards.');
        } else {
            $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . $line . "\n";
        }
        if (@file_put_contents($envFile, $contents) === false) {
            $io->error(\sprintf('Cannot write %s.', $envFile));

            return ExitCode::FAILURE;
        }
        $io->writeln(\sprintf('<info>INDEXNOW_KEY written to %s.</info>', $envFile));
        $io->text(\sprintf('The key file is served at /<key>.txt %s. Verify with: %s indexnow:check', $this->words->keyFileServedBy, $this->words->cli));

        return ExitCode::SUCCESS;
    }
}
