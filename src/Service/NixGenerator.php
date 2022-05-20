<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Nixify\Service;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Url as UrlUtil;
use Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\ExecutableFinder;

use function count;
use function dirname;
use function strlen;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

final class NixGenerator
{
    private string $cacheDir;

    private Composer $composer;

    private Config $config;

    private Filesystem $fs;

    private IOInterface $io;

    private NixUtils $nixUtils;

    private bool $shouldPreload;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->config = $composer->getConfig();
        $this->fs = new Filesystem();
        $this->nixUtils = new NixUtils();

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
                    $urls = $package->getDistUrls();

                    // Cache is keyed by URL. Use the first URL to derive a
                    // cache key, because Composer also tries URLs in order.
                    $cacheUrl = current($urls);

                    // From: `FileDownloader::getCacheKey()`
                    if (null !== $package->getDistReference()) {
                        $cacheUrl = UrlUtil::updateDistReference(
                            $this->config,
                            $cacheUrl,
                            $package->getDistReference()
                        );
                    }

                    $cacheKey = sprintf(
                        '%s/%s.%s',
                        $package->getName(),
                        sha1($cacheUrl),
                        $package->getDistType()
                    );

                    // From: `Cache::read()`
                    $cacheFile = preg_replace('{[^a-z0-9_./]}i', '-', $cacheKey);

                    // Collect package info.
                    $name = $this->safeNixStoreName($package->getUniqueName());

                    if (false === $sha256 = hash_file('sha256', $this->cacheDir . $cacheFile)) {
                        $sha256 = $this->fetch($package, $name, $cacheFile);
                    }

                    yield [
                        'package' => $package,
                        'type' => 'cache',
                        'name' => $name,
                        'urls' => $urls,
                        'cacheFile' => $cacheFile,
                        'sha256' => $sha256,
                    ];

                    break;

                case 'path':
                    yield [
                        'package' => $package,
                        'type' => 'local',
                        'name' => $this->safeNixStoreName($package->getName()),
                        'path' => $package->getDistUrl(),
                    ];

                    break;

                default:
                    $this
                        ->io
                        ->warning(
                            sprintf(
                                "Package '%s' has dist-type '%s' which is not" .
                                ' supported by the Nixify plugin',
                                $package->getPrettyName(),
                                $package->getDistType()
                            )
                        );

                    break;
            }
        }
    }

    /**
     * Generates Nix files based on the lockfile and cache.
     */
    public function generate(array $collected): void
    {
        $package = $this->composer->getPackage();

        // Build cached entries.
        $cacheEntries = array_filter(
            $collected,
            static fn (array $info): bool => 'cache' === $info['type']
        );

        // Build local entries.
        $localEntries = array_filter(
            $collected,
            static fn (array $info): bool => 'local' === $info['type']
        );

        // If the user bundled Composer, use that in the Nix build as well.
        $cwd = getcwd() . '/';
        $composerPath = realpath($_SERVER['PHP_SELF']);

        $composerPath = substr($composerPath, 0, strlen($cwd)) === $cwd
            ? substr($composerPath, strlen($cwd))
            : null;

        $jsonData = [
            'cacheEntries' => $cacheEntries,
            'composerPath' => $composerPath,
            'localEntries' => $localEntries,
            'projectName' => self::safeNixStoreName($package->getName())
        ];

        $searchNreplace = [
            '{{json}}' => json_encode(
                array_filter($jsonData),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ),
        ];

        file_put_contents(
            $package->getExtra()['nix-expr-path'] ?? 'composer-project.nix',
            str_replace(
                array_keys($searchNreplace),
                array_values($searchNreplace),
                file_get_contents(__DIR__ . '/../../res/composer-project.nix')
            )
        );

        // Generate default.nix if it does not exist yet.
        $generateDefaultNix = $package->getExtra()['generate-default-nix'] ?? true;

        if ($generateDefaultNix && !file_exists('default.nix') && !file_exists('flake.nix')) {
            file_put_contents('default.nix', file_get_contents(__DIR__ . '/../res/default.nix'));
            $this->io->writeError(
                '<info>A minimal default.nix was created. You may want to customize it.</info>'
            );
        }
    }

    /**
     * Preload packages into the Nix store.
     */
    public function preload(array $collected): void
    {
        $tempDir = sprintf(
            '%s.nixify-tmp-%s',
            $this->cacheDir,
            substr(md5(uniqid('', true)), 0, 8)
        );
        $this->fs->ensureDirectoryExists($tempDir);

        $toPreload = array_filter(
            array_map(
                function (array $info) use ($tempDir): ?string {
                    $storePath = $this
                        ->nixUtils
                        ->computeFixedOutputStorePath(
                            $info['name'],
                            'sha256',
                            $info['sha256']
                        );

                    if (file_exists($storePath)) {
                        return null;
                    }

                    // The nix-store command requires a correct filename on
                    // disk, so we prepare a temporary directory containing all
                    // the files to preload.
                    $dst = sprintf('%s/%s', $tempDir, $info['name']);
                    $copy = $this
                        ->fs
                        ->copy(
                            sprintf(
                                '%s%s',
                                $this->cacheDir,
                                $info['cacheFile']
                            ),
                            $dst
                        );

                    if (false === $copy) {
                        $this
                            ->io
                            ->writeError(
                                '<error>' .
                                'Preloading into Nix store failed: could' .
                                ' not write to temporary directory.' .
                                '</error>'
                            );

                        return null;
                    }

                    return $dst;
                },
                array_filter(
                    $collected,
                    static fn (array $info): bool => 'cache' === $info['type']
                )
            )
        );

        if ([] === $toPreload) {
            return;
        }

        try {
            // Preload in batches, to keep the exec arguments reasonable.
            $process = new ProcessExecutor($this->io);
            $numPreloaded = 0;

            foreach (array_chunk($toPreload, 100) as $chunk) {
                $command = sprintf(
                    'nix-store --add-fixed sha256 %s',
                    implode(
                        ' ',
                        array_map(['Composer\\Util\\ProcessExecutor', 'escape'], $chunk)
                    )
                );

                if ($process->execute($command, $output) !== Command::SUCCESS) {
                    $this->io->writeError('<error>Preloading into Nix store failed.</error>');
                    $this->io->writeError($output);

                    break;
                }

                $numPreloaded += count($chunk);
            }

            $this
                ->io
                ->writeError(
                    sprintf(
                        '<info>Preloaded %d packages into the Nix store.</info>',
                        $numPreloaded
                    )
                );
        } finally {
            $this->fs->removeDirectory($tempDir);
        }
    }

    public function shouldPreload(): bool
    {
        return $this->shouldPreload;
    }

    private function fetch(
        BasePackage $package,
        string $name,
        string $cacheFile
    ): string {
        // If some packages were previously installed but since removed from
        // cache, `sha256` will be false for those packages.
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
        $promise = $downloader
            ->download($package, $tempDir)
            ->then(
                static function (string $filename) use (&$tempFile): void {
                    $tempFile = $filename;
                }
            );
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
     *
     * @return Generator<int, BasePackage>
     */
    private function iterLockedPackages(): Generator
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
    private function safeNixStoreName(string $value): string
    {
        return preg_replace('/[^a-z0-9._-]/i', '_', $value);
    }
}
