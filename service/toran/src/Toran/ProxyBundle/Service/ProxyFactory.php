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
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\AliasPackage;
use Composer\Downloader\FileDownloader;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Package\PackageInterface;
use Composer\Util\ComposerMirror;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\IO\IOInterface;
use Composer\Config as ComposerConfig;

class ProxyFactory
{
    private $config;
    private $sourceSyncer;
    private $toranWebDir;
    private $toranCacheDir;
    private $urlGenerator;

    public function __construct(UrlGeneratorInterface $urlGenerator, Configuration $config, SourceSyncer $sourceSyncer, $toranWebDir, $toranCacheDir)
    {
        $this->urlGenerator = $urlGenerator;
        $this->config = $config;
        $this->sourceSyncer = $sourceSyncer;
        $this->toranWebDir = realpath($toranWebDir);
        $this->toranCacheDir = $toranCacheDir;
    }

    public function createProxy($repo, IOInterface $io = null, ComposerConfig $config = null)
    {
        if ($repo === 'packagist') {
            if ('proxy' !== $this->config->get('packagist_sync')) {
                throw new \LogicException('This should not be called if packagist sync is disabled');
            }

            $repoUrl = 'https://packagist.org';
            if (!$config) {
                $config = Factory::createConfig();
            }
            if (!$io) {
                $io = new NullIO;
                $io->loadConfiguration($config);
            }
            $rfs = new RemoteFilesystem($io, $config);
            $downloader = new FileDownloader($io, $config, null, null, $rfs);
            $downloader->setOutputProgress(false);

            return new Proxy(
                $this->urlGenerator,
                $this->config,
                $this->sourceSyncer,
                $repo,
                $repoUrl,
                $this->toranWebDir,
                $this->toranCacheDir,
                $rfs,
                $downloader
            );
        }

        throw new \UnexpectedValueException('Unknown repository: '.$repo);
    }
}
