<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Nixify;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Url as UrlUtil;
use Generator;
use Symfony\Component\Process\ExecutableFinder;

use function count;
use function dirname;
use function strlen;

use const JSON_UNESCAPED_SLASHES;

final class NixGenerator
{
    public $shouldPreload;

    private string $cacheDir;

    private Composer $composer;

    private Config $config;

    private Filesystem $fs;

    private IOInterface $io;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->config = $composer->getConfig();
        $this->fs = new Filesystem();

        // From: `Cache::__construct()`
        $this->cacheDir = rtrim($this->config->get('cache-files-dir'), '/\\') . '/';

        $this->shouldPreload = $composer->getPackage()->getExtra()['enable-nix-preload'] ?? true;

        if ($this->shouldPreload) {
            $exeFinder = new ExecutableFinder();
            $this->shouldPreload = (bool) $exeFinder->find('nix-store');
        }
    }

    public function collect(): Generator
    {
        foreach ($this->iterLockedPackages() as $package) {
            switch ($package->getDistType()) {
                case 'tar':
                case 'xz':
                case 'zip':
                case 'gzip':
                case 'phar':
                case 'rar':
                    $type = 'cache';
                    $urls = $package->getDistUrls();

                    // Cache is keyed by URL. Use the first URL to derive a
                    // cache key, because Composer also tries URLs in order.
                    $cacheUrl = current($urls);

                    // From: `FileDownloader::getCacheKey()`
                    if (null !== $package->getDistReference()) {
                        $cacheUrl = UrlUtil::updateDistReference($this->config, $cacheUrl, $package->getDistReference());
                    }
                    $cacheKey = sprintf('%s/%s.%s', $package->getName(), sha1($cacheUrl), $package->getDistType());

                    // From: `Cache::read()`
                    $cacheFile = preg_replace('{[^a-z0-9_./]}i', '-', $cacheKey);

                    // Collect package info.
                    $name = self::safeNixStoreName($package->getUniqueName());

                    if (false === $sha256 = hash_file('sha256', $this->cacheDir . $cacheFile)) {
                        $sha256 = $this->fetch($package, $type, $name, $cacheFile);
                    }

                    yield compact('package', 'type', 'name', 'urls', 'cacheFile', 'sha256');

                    break;

                case 'path':
                    $type = 'local';
                    $name = self::safeNixStoreName($package->getName());
                    $path = $package->getDistUrl();

                    yield compact('package', 'type', 'name', 'path');

                    break;

                default:
                    $this->io->warning(sprintf(
                        "Package '%s' has dist-type '%s' which is not supported by the Nixify plugin",
                        $package->getPrettyName(),
                        $package->getDistType()
                    ));

                    break;
            }
        }
    }

    /**
     * Generates Nix files based on the lockfile and cache.
     */
    public function generate(): void
    {
        $collected = iterator_to_array($this->collect());

        // Build Nix code for cache entries.
        $cacheEntries = sprintf(
            '[\n%s\n]',
            array_reduce(
                array_filter($collected, static fn (array $info): bool => 'cache' === $info['type']),
                static function (string $carry, array $info): string {
                    return sprintf(
                        '%s%s',
                        $carry,
                        sprintf(
                            "{ name = %s; filename = %s; sha256 = %s; urls = %s; }\n",
                            self::nixString($info['name']),
                            self::nixString($info['cacheFile']),
                            self::nixString($info['sha256']),
                            self::nixStringArray($info['urls'])
                        )
                    );
                },
                ''
            )
        );

        // Build Nix code for local entries.
        $localPackages = sprintf(
            '[\n%s\n]',
            array_reduce(
                array_filter($collected, static fn (array $info): bool => 'local' === $info['type']),
                static function (string $carry, array $info): string {
                    return sprintf(
                        '%s%s',
                        $carry,
                        sprintf(
                            "    { path = %s; string = %s; }\n",
                            $info['path'],
                            self::nixString($info['path']),
                        )
                    );
                },
                ''
            )
        );

        // If the user bundled Composer, use that in the Nix build as well.
        $cwd = getcwd() . '/';
        $composerPath = realpath($_SERVER['PHP_SELF']);

        if (substr($composerPath, 0, strlen($cwd)) === $cwd) {
            $composerPath = self::nixString(substr($composerPath, strlen($cwd)));
        } else {
            // Otherwise, use Composer from Nixpkgs. Don't reference the wrapper,
            // which depends on a specific PHP version, but the src phar instead.
            $composerPath = 'phpPackages.composer.src';
        }

        // Use the root package name as default derivation name.
        $package = $this->composer->getPackage();
        $projectName = self::nixString(self::safeNixStoreName($package->getName()));

        // Generate composer-project.nix.
        $projectFile = $package->getExtra()['nix-expr-path'] ?? 'composer-project.nix';

        $search = [
            '{$composerPath}' => $composerPath,
            '{$projectName}' => $projectName,
            '{$cacheEntries}' => $cacheEntries,
            '{$localEntries}' => $localPackages,
        ];
        $content = file_get_contents(__DIR__ . '/../res/composer-project.nix.php');

        $replaced_string = str_replace(array_keys($search), array_values($search), $content);

        file_put_contents($projectFile, $replaced_string);

        // Generate default.nix if it does not exist yet.
        $generateDefaultNix = $package->getExtra()['generate-default-nix'] ?? true;

        if ($generateDefaultNix && !file_exists('default.nix') && !file_exists('flake.nix')) {
            file_put_contents('default.nix', file_get_contents(__DIR__ . '/../res/default.nix.php'));
            $this->io->writeError(
                '<info>A minimal default.nix was created. You may want to customize it.</info>'
            );
        }
    }

    /**
     * Preload packages into the Nix store.
     */
    public function preload(): void
    {
        $tempDir = $this->cacheDir . '.nixify-tmp-' . substr(md5(uniqid('', true)), 0, 8);
        $this->fs->ensureDirectoryExists($tempDir);

        try {
            $toPreload = [];

            foreach ($this->collected as $info) {
                if ('cache' !== $info['type']) {
                    continue;
                }
                $storePath = NixUtils::computeFixedOutputStorePath($info['name'], 'sha256', $info['sha256']);

                if (!file_exists($storePath)) {
                    // The nix-store command requires a correct filename on disk, so we
                    // prepare a temporary directory containing all the files to preload.
                    $src = $this->cacheDir . $info['cacheFile'];
                    $dst = sprintf('%s/%s', $tempDir, $info['name']);

                    if (!copy($src, $dst)) {
                        $this->io->writeError(
                            '<error>Preloading into Nix store failed: could not write to temporary directory.</error>'
                        );

                        break;
                    }

                    $toPreload[] = $dst;
                }
            }

            if (!empty($toPreload)) {
                // Preload in batches, to keep the exec arguments reasonable.
                $process = new ProcessExecutor($this->io);
                $numPreloaded = 0;

                foreach (array_chunk($toPreload, 100) as $chunk) {
                    $command = 'nix-store --add-fixed sha256 '
                        . implode(' ', array_map(['Composer\\Util\\ProcessExecutor', 'escape'], $chunk));

                    if ($process->execute($command, $output) !== 0) {
                        $this->io->writeError('<error>Preloading into Nix store failed.</error>');
                        $this->io->writeError($output);

                        break;
                    }
                    $numPreloaded += count($chunk);
                }

                $this->io->writeError(sprintf(
                    '<info>Preloaded %d packages into the Nix store.</info>',
                    $numPreloaded
                ));
            }
        } finally {
            $this->fs->removeDirectory($tempDir);
        }
    }

    private function fetch(
        BasePackage $package,
        string $name,
        string $cacheFile
    ): string {
        // If some packages were previously installed but since removed from
        // cache, `sha256` will be false for those packages in `collected`.
        // Here, we amend cache by refetching, so we can then determine the
        // file hash again.
        $downloader = $this->composer->getDownloadManager()->getDownloader('file');

        $tempDir = $this->cacheDir . '.nixify-tmp-' . substr(md5(uniqid('', true)), 0, 8);
        $this->fs->ensureDirectoryExists($tempDir);

        $this->io->writeError(sprintf(
            '<info>Nixify could not find cache for package $s, which will be refetched</info>',
            $name
        ));

        $this->io->writeError(sprintf(
            '  - Fetching <info>%s</info> (<comment>%s</comment>): ',
            $package->getName(),
            $package->getFullPrettyVersion()
        ), false);

        $tempFile = '';
        $promise = $downloader->download($package, $tempDir, null, false)
            ->then(static function ($filename) use (&$tempFile) {
                $tempFile = $filename;
            });
        $this->composer->getLoop()->wait([$promise]);
        $this->io->writeError('OK');

        $cachePath = sprintf('%s%s', $this->cacheDir, $cacheFile);
        $this->fs->ensureDirectoryExists(dirname($cachePath));
        $this->fs->rename($tempFile, $cachePath);
        $hash = hash_file('sha256', $cachePath);
        $this->fs->removeDirectory($tempDir);

        return $hash;
    }

    /**
     * Generator function that iterates lockfile packages.
     */
    private function iterLockedPackages()
    {
        $locker = $this->composer->getLocker();

        if ($locker->isLocked() === false) {
            return;
        }

        $data = $locker->getLockData();
        $loader = new ArrayLoader(null, true);

        foreach ($data['packages'] ?? [] as $info) {
            yield $loader->load($info);
        }

        foreach ($data['packages-dev'] ?? [] as $info) {
            yield $loader->load($info);
        }
    }

    /**
     * Naive conversion of a string to a Nix literal.
     */
    private static function nixString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Naive conversion of a string array to a Nix literal.
     */
    private static function nixStringArray(array $value): string
    {
        $strings = array_map([self::class, 'nixString'], $value);

        return '[ ' . implode(' ', $strings) . ' ]';
    }

    /**
     * Sanitizes a string so it's safe to use as a Nix store name.
     */
    private static function safeNixStoreName(string $value): string
    {
        return preg_replace('/[^a-z0-9._-]/i', '_', $value);
    }
}
