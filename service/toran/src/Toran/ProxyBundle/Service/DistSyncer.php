<?php

/*
 * This file is part of the Toran package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Toran\ProxyBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Downloader\FileDownloader;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Util\ComposerMirror;
use Composer\Factory;
use Composer\Config as ComposerConfig;
use Composer\IO\IOInterface;
use Composer\IO\ConsoleIO;
use Composer\Package\Archiver\ArchiveManager;

class DistSyncer
{
    const DIST_FORMAT = '/%package%/%version%/%reference%.%type%';

    private $urlGenerator;
    private $format = 'zip';

    public function __construct(UrlGeneratorInterface $urlGenerator)
    {
        $this->urlGenerator = $urlGenerator;
    }

    public function sync(IOInterface $io, ComposerConfig $config, array $packages, $targetDir, $distSyncMode)
    {
        $downloader = new FileDownloader($io, $config);
        $downloader->setOutputProgress($io instanceof ConsoleIO);

        $factory = new Factory();
        /* @var \Composer\Package\Archiver\ArchiveManager $archiveManager */
        $archiveManager = $factory->createArchiveManager($config);
        $archiveManager->setOverwriteFiles(false);

        $packagesByName = array();
        foreach ($packages as $package) {
            $packagesByName[$package->getName()][] = $package;
        }

        foreach ($packagesByName as $packages) {
            $packages = Util::sortByVersion($packages);

            $syncDists = $distSyncMode === 'all';

            /* @var \Composer\Package\CompletePackage $package */
            foreach ($packages as $package) {
                if ($package instanceof AliasPackage || $package->getType() === 'metapackage') {
                    continue;
                }
                if (!$package->getDistUrl() && !$package->getSourceUrl()) {
                    continue;
                }

                $cacheFile = $this->getCacheFile($targetDir, $package);
                if ($syncDists) {
                    $this->downloadPackage($io, $downloader, $archiveManager, $package, $targetDir, $cacheFile);
                } elseif ($distSyncMode === 'new' && !$package->isDev() && file_exists($cacheFile)) {
                    // non-dev releases that are synced trigger a sync of all newer releases if distSyncMode is 'new'
                    $syncDists = true;
                }

                // we always update package info to make sure the lazy urls are ready even if a package isn't in cache
                $this->updatePackageInfo($package, $cacheFile);
            }
        }
    }

    private function downloadPackage(IOInterface $io, FileDownloader $downloader, ArchiveManager $archiveManager, PackageInterface $package, $targetDir, $cacheFile = null)
    {
        if (null === $cacheFile) {
            $cacheFile = $this->getCacheFile($targetDir, $package);
        }

        if (!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0777, true);
        }

        if (!file_exists($cacheFile)) {
            if ($url = $package->getDistUrl()) {
                try {
                    $path = $downloader->download($package, $targetDir.'/tempDownload');
                    rename($path, $cacheFile);
                    rmdir($targetDir.'/tempDownload');
                    // TODO enable if it ever works
                    // $package->setDistSha1Checksum(hash_file('sha1', $cacheFile));
                    $io->write(sprintf("<info>Downloaded existing dist file for '%s'.</info>", $package));

                    return $cacheFile;
                } catch (\Exception $e) {
                    $io->write("<error>".$e->getMessage().".</error>");
                }
            }

            $io->write(sprintf("<info>Creating dist file for '%s'.</info>", $package));

            $path = $archiveManager->archive($package, $package->getDistType() ?: $this->format, $targetDir);
            rename($path, $cacheFile);
        }

        return $cacheFile;
    }

    private function getCacheFile($targetDir, PackageInterface $package)
    {
        return ComposerMirror::processUrl(
            $targetDir.self::DIST_FORMAT,
            $package->getName(),
            $package->getVersion(),
            $package->getDistReference() ?: $package->getSourceReference(),
            $package->getDistType() ?: $this->format
        );
    }

    private function updatePackageInfo(PackageInterface $package, $cacheFile)
    {
        if (!$package->getDistUrl()) {
            $package->setDistType($package->getDistType() ?: $this->format);
            $package->setDistReference($package->getSourceReference());
            $distUrl = Proxy::generateDistUrl(
                $this->urlGenerator,
                'private',
                $package->getName(),
                $package->getVersion(),
                $package->getDistReference(),
                $this->format
            );
            $package->setDistUrl($distUrl);
        }
        // TODO enable if it ever works
        // $package->setDistSha1Checksum(hash_file('sha1', $cacheFile));
    }
}
