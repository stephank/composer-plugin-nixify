<?php

namespace Nixify;

use Composer\Composer;
use Composer\Downloader\FileDownloader;
use Composer\IO\IOInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Url as UrlUtil;
use Symfony\Component\Process\ExecutableFinder;

class NixGenerator
{
    private $composer;
    private $io;
    private $config;
    private $fs;
    private $cacheDir;
    private $collected;
    public $canPreload;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->config = $composer->getConfig();
        $this->fs = new Filesystem();

        // From: `Cache::__construct()`
        $this->cacheDir = rtrim($this->config->get('cache-files-dir'), '/\\') . '/';

        $this->collected = [];

        $exeFinder = new ExecutableFinder;
        $this->canPreload = (bool) $exeFinder->find('nix-store');
    }

    public function collect(): void
    {
        // Collect lockfile packages we know how to create Nix fetch
        // derivations for.
        $this->collected = [];
        $numToFetch = 0;

        foreach ($this->iterLockedPackages() as $package) {
            switch ($package->getDistType()) {
                case 'tar':
                case 'xz':
                case 'zip':
                case 'gzip':
                case 'phar':
                case 'rar':
                    $urls = $package->getDistUrls();

                    // Cache is keyed by URL. Use the first URL to derive a
                    // cache key, because Composer also tries URLs in order.
                    $cacheUrl = $urls[0];

                    // From: `FileDownloader::getCacheKey()`
                    if ($package->getDistReference()) {
                        $cacheUrl = UrlUtil::updateDistReference($this->config, $cacheUrl, $package->getDistReference());
                    }
                    $cacheKey = sprintf('%s/%s.%s', $package->getName(), sha1($cacheUrl), $package->getDistType());

                    // From: `Cache::read()`
                    $cacheFile = preg_replace('{[^a-z0-9_./]}i', '-', $cacheKey);

                    // Collect package info.
                    $name = self::safeNixStoreName($package->getUniqueName());
                    $sha256 = @hash_file('sha256', $this->cacheDir . $cacheFile);
                    $this->collected[] = compact('package', 'name', 'urls', 'cacheFile', 'sha256');

                    if ($sha256 === false) {
                        $numToFetch += 1;
                    }

                    break;

                default:
                    $this->io->warning(sprintf(
                        "Package '%s' has dist-type '%s' which is not support by the Nixify plugin",
                        $package->getPrettyName(),
                        $package->getDistType()
                    ));
                    break;
            }
        }

        // If some packages were previously installed but since removed from
        // cache, `sha256` will be false for those packages in `collected`.
        // Here, we amend cache by refetching, so we can then determine the
        // file hash again.
        if ($numToFetch !== 0) {
            $this->io->writeError(sprintf(
                '<info>Nixify could not find cache for %d package(s), which will be refetched</info>',
                $numToFetch
            ));

            $downloader = new FileDownloader($this->io, $this->config);
            $tempDir = $this->cacheDir . '.nixify-tmp-' . substr(md5(uniqid('', true)), 0, 8);
            $this->fs->ensureDirectoryExists($tempDir);
            try {
                foreach ($this->collected as &$info) {
                    if ($info['sha256'] !== false) {
                        continue;
                    }

                    $package = $info['package'];

                    $this->io->writeError(sprintf(
                        '  - Fetching <info>%s</info> (<comment>%s</comment>): ',
                        $package->getName(),
                        $package->getFullPrettyVersion()
                    ), false);
                    $tempFile = $downloader->download($package, $tempDir, false);
                    $this->io->writeError('');

                    $cachePath = $this->cacheDir . $info['cacheFile'];
                    $this->fs->ensureDirectoryExists(dirname($cachePath));
                    $this->fs->rename($tempFile, $cachePath);

                    $info['sha256'] = hash_file('sha256', $cachePath);
                }
            } finally {
                $this->fs->removeDirectory($tempDir);
            }
        }
    }

    /**
     * Generates Nix files based on the lockfile and cache.
     */
    public function generate(): void
    {
        // Build Nix code for cache entries.
        $cacheEntries = "[\n";
        foreach ($this->collected as $info) {
            $cacheEntries .= sprintf(
                "    { name = %s; filename = %s; sha256 = %s; urls = %s; }\n",
                self::nixString($info['name']),
                self::nixString($info['cacheFile']),
                self::nixString($info['sha256']),
                self::nixStringArray($info['urls'])
            );
        }
        $cacheEntries .= '  ]';

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
        $projectName = self::nixString(self::safeNixStoreName(
            $this->composer->getPackage()->getName())
        );

        // Generate composer-project.nix.
        ob_start();
        require __DIR__ . '/../res/composer-project.nix.php';
        file_put_contents('composer-project.nix', ob_get_clean());

        // Generate default.nix if it does not exist yet.
        if (!file_exists('default.nix') && !file_exists('flake.nix')) {
            ob_start();
            require __DIR__ . '/../res/default.nix.php';
            file_put_contents('default.nix', ob_get_clean());
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
                    $command = "nix-store --add-fixed sha256 "
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
     * Sanitizes a string so it's safe to use as a Nix store name.
     */
    private static function safeNixStoreName(string $value): string
    {
        return preg_replace('/[^a-z0-9._-]/i', '_', $value);
    }

    /**
     * Naive conversion of a string to a Nix literal
     */
    private static function nixString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Naive conversion of a string array to a Nix literal
     */
    private static function nixStringArray(array $value): string
    {
        $strings = array_map([self::class, 'nixString'], $value);
        return '[ ' . implode(' ', $strings) . ' ]';
    }
}
