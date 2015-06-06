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
use Symfony\Component\Finder\Finder;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\AliasPackage;
use Composer\Downloader\FileDownloader;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Package\PackageInterface;
use Composer\Util\ComposerMirror;
use Composer\IO\NullIO;
use Composer\IO\IOInterface;
use Composer\Factory;
use Composer\Config as ComposerConfig;

class Proxy
{
    const CACHE_FORMAT = '/dists/%package%/%version%_%reference%.%type%';
    const GIT_CACHE_FORMAT = '/%package%/%normalizedUrl%.%type%';
    private $config;
    private $webDir;
    private $sourceSyncer;
    private $repoUrl;
    private $repoUrlId;
    private $repoName;
    private $cacheDir;
    private $rfs;
    private $downloader;
    private $origNotifyUrl;
    private $generator;

    public function __construct(UrlGeneratorInterface $generator, Configuration $config, SourceSyncer $sourceSyncer, $repoName, $repoUrl, $webDir, $cacheDir, RemoteFilesystem $remoteFilesystem, FileDownloader $downloader)
    {
        if ($repoName !== 'packagist') {
            throw new \LogicException('This class currently only can proxy packagist, see addSyncedPackage & co');
        }
        $this->repoUrlId = self::createRepoId($repoUrl);
        $this->generator = $generator;
        $this->config = $config;
        $this->sourceSyncer = $sourceSyncer;
        $this->repoUrl = rtrim($repoUrl, '/');
        $this->repoName = $repoName;
        $this->webDir = rtrim($webDir, '/\\');
        $this->cacheDir = rtrim($cacheDir, '/\\') . '/' . $this->repoUrlId;
        $this->rfs = $remoteFilesystem;
        $this->downloader = $downloader;
    }

    /**
     * Loads the packages.json file
     *
     * - from local cache if it's fresh (<60s)
     * - or from remote if available
     * - or from stale cache with warning
     */
    public function getRootFile()
    {
        $rootCacheFile = $this->cacheDir.'/p/packages.json';
        if (file_exists($rootCacheFile) && filemtime($rootCacheFile) > time() - 60) {
            // TODO LOW rebuild provider-includes from the packages we already have in cache (if they are not stale?)
            return file_get_contents($rootCacheFile);
        }

        $opts = array('http' => array('timeout' => 4));
        $contents = $this->getContents($this->repoUrl.'/packages.json', $opts);
        if ($contents) {
            if (!is_dir($this->cacheDir.'/p')) {
                mkdir($this->cacheDir.'/p', 0777, true);
            }
            if (!is_dir($this->cacheDir.'/raw')) {
                mkdir($this->cacheDir.'/raw', 0777, true);
            }

            $data = json_decode($contents, true);
            unset($data['providers-includes'], $data['includes'], $data['notify_batch'], $data['notify'], $data['provider-includes']);
            if (empty($data['packages'])) {
                unset($data['packages']);
            }
            if (!empty($data['search']) && $data['search'][0] === '/') {
                $data['search'] = $this->repoUrl . $data['search'];
            }
            $distUrl = self::generateDistUrl($this->generator, $this->repoName, '%package%', '%version%', '%reference%', '%type%');
            $mirror = array(
                'dist-url' => $distUrl,
                'preferred' => true, // preferred method of installation, puts it above the default url
            );
            if ($gitPrefix = $this->config->get('git_prefix')) {
                $mirror['git-url'] = rtrim($gitPrefix, '/') . self::GIT_CACHE_FORMAT;
            }

            if (!empty($data['mirrors'])) {
                array_unshift($data['mirrors'], $mirror);
            } else {
                $data['mirrors'] = array($mirror);
            }

            $lazyUrl = $this->generator->generate('toran_proxy_providers', array('repo' => $this->repoName, 'filename' => 'PACKAGE.json'));
            $data['providers-lazy-url'] = str_replace('PACKAGE', '%package%', $lazyUrl);
            if (isset($data['notify-batch'])) {
                $data['notify-batch'] = 0 === strpos($data['notify-batch'], '/') ? rtrim($this->repoUrl, '/') . $data['notify-batch'] : $data['notify-batch'];
            }

            // TODO LOW build out these files and add support for mixed repos in ComposerRepository
            // $data['providers-url'] = '/p/%package%$%hash%.json';

            file_put_contents($this->cacheDir . '/raw/packages.json', $contents);
            $contents = json_encode($data);

            file_put_contents($rootCacheFile, $contents);

            return $contents;
        }

        if ($contents = @file_get_contents($rootCacheFile)) {
            $data = json_decode($contents, true);
            $data['warning'] = 'This is an old cached copy, '.$this->repoUrl.' could not be reached';
            return json_encode($data);
        }

        throw new \RuntimeException('Failed to fetch '.$this->repoUrl.'/packages.json and it is not cached yet');
    }

    /**
     * Generates a mirrorred dist download URL for the given repository config
     */
    public static function generateDistUrl(UrlGeneratorInterface $generator, $repoName, $package, $version, $ref, $type)
    {
        $distUrl = $generator->generate('toran_proxy_dists', array(
            'repo' => 'REPONAME',
            'name' => 'PACK/AGE',
            'version' => 'VERSION',
            'ref' => 'abcd',
            'type' => 'zip',
        ), true);
        $distUrl = substr($distUrl, 0, -8).'REF.TYPE';

        $distUrl = str_replace(
            array('REPONAME', 'PACK/AGE', 'VERSION', 'REF', 'TYPE'),
            array($repoName, '%package%', '%version%', '%reference%', '%type%'),
            $distUrl
        );

        return ComposerMirror::processUrl($distUrl, $package, $version, $ref, $type);
    }

    /**
     * Generates a filesystem-compliant unique identifier for a given repository url
     */
    public static function createRepoId($repoUrl)
    {
        $repoUrlId = preg_replace('{/packages\.json$}', '', $repoUrl);
        $repoUrlId = preg_replace('{[^a-z0-9_.-]}i', '-', trim($repoUrlId, '/'));
        if ($repoUrlId === 'https---packagist.org' || $repoUrlId === 'http---packagist.org') {
            $repoUrlId = 'packagist';
        }

        return $repoUrlId;
    }

    /**
     * Creates a dist filename for a given package version and
     * preloads the dist from the original URL if it does not exist in
     * the local cache
     */
    public function getDistFilename($name, $version, $ref, $format)
    {
        $cacheFile = ComposerMirror::processUrl($this->cacheDir.self::CACHE_FORMAT, $name, $version, $ref, $format);

        if (!file_exists($cacheFile)) {
            $providerPath = $this->getProviderPath(preg_replace('{\.json$}', '', $name.'.json'));
            if ($providerPath && file_exists($this->cacheDir.$providerPath)) {
                $packages = json_decode(file_get_contents($this->cacheDir.$providerPath), true);
                if (!empty($packages['packages'][$name])) {
                    foreach ($packages['packages'][$name] as $package) {
                        if ($package['version_normalized'] === $version && isset($package['dist']['url'])) {
                            return $this->downloadPackage($package, $cacheFile);
                        }
                    }
                }
            }

            return '';
        }

        return $cacheFile;
    }

    public function downloadPackage(array $packageData, $cacheFile = null)
    {
        if (null === $cacheFile) {
            $cacheFile = $this->getCacheFile($packageData);
        }

        if (!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0777, true);
        }

        if (!file_exists($cacheFile)) {
            $loader = new ArrayLoader();
            $package = $loader->load($packageData);

            $path = $this->downloader->download($package, $this->cacheDir.'/tempDownload');
            rename($path, $cacheFile);
            rmdir($this->cacheDir.'/tempDownload');
            // TODO LOW somehow cache that this has been downloaded somewhere?
        }

        return $cacheFile;
    }

    public function hasPackageInCache(array $packageData)
    {
        return file_exists($this->getCacheFile($packageData));
    }

    /**
     * Retrieves the original provider file (p/acme/foo$hash.json) from the original repo
     *
     * - from cache if possible
     * - or fetches it from origin
     * - or warns the user
     */
    public function getProviderFile($filename, IOInterface $io = null, ComposerConfig $config = null)
    {
        if (false !== strpos($filename, '$')) {
            return false;
        }

        $filename = preg_replace('{\.json$}', '', $filename);
        $providerPath = $this->getProviderPath($filename);
        if (!$providerPath) {
            $data = array(
                'packages' => array(),
                'warning' => 'The original providers from '.$this->repoUrl.' could not be fetched',
            );

            return json_encode($data);
        }

        $cacheFile = $this->cacheDir.$providerPath;
        if (!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0777, true);
        }

        if (file_exists($cacheFile) && trim($contents = file_get_contents($cacheFile))) {
            // skip, contents is primed
        } elseif ($contents = $this->getContents($this->repoUrl.$providerPath)) {
            $data = json_decode($contents, true);
            if ($origNotifyUrl = $this->getOriginalNotifyUrl()) {
                foreach ($data['packages'] as $index => $package) {
                    foreach ($package as $version => $dummy) {
                        $data['packages'][$index][$version]['notification-url'] = $origNotifyUrl;
                    }
                }
            }

            if ($data && isset($data['packages']) && is_array($data['packages'])) {
                $contents = json_encode($data);
                file_put_contents($cacheFile, $contents);
            }
            // TODO LOW start background dist/source sync job for the packages contained in this file
            // TODO LOW update cached package file once the sync job is complete

            if (null === $io) {
                $io = new NullIO;
            }
            if (null === $config) {
                $config = Factory::createConfig();
                $io->loadConfiguration($config);
            }

            $this->cleanOldFiles($cacheFile);
            $this->sourceSyncer->sync($io, $config, $this->loadPackages($data, $filename));
        } else {
            $contents = json_encode(array(
                'packages' => array(),
                'warning' => 'The original file '.$this->repoUrl.$providerPath.' could not be fetched',
            ));
        }

        return $contents;
    }

    public function removePackage($packageName)
    {
        // attempt source deletion
        $packageData = json_decode($this->getProviderFile($packageName), true);
        if (isset($packageData['packages'][$packageName])) {
            $package = $this->loadPackage(current($packageData['packages'][$packageName]));
            $this->sourceSyncer->removePackage($package);
        }

        // clear dist files
        $cacheFileMask = ComposerMirror::processUrl(
            $this->cacheDir.self::CACHE_FORMAT,
            $packageName,
            '*',
            '0000000000000000000000000000000000000000',
            '*'
        );
        $cacheFileMask = str_replace('0000000000000000000000000000000000000000', '*', $cacheFileMask);
        $files = glob($cacheFileMask) ?: array();
        foreach ($files as $file) {
            @unlink($file);
        }

        // remove from config
        $this->config->removeSyncedPackage($packageName);
        $this->config->save();
    }

    private function loadPackages(array $data, $filter = null)
    {
        $packages = array();

        $loader = new ArrayLoader();
        foreach ($data['packages'] as $package => $versions) {
            if ($filter && $filter !== $package) {
                continue;
            }
            foreach ($versions as $version) {
                $packages[] = $loader->load($version);
            }
        }

        return $packages;
    }

    private function loadPackage(array $data, ArrayLoader $loader = null)
    {
        if (!$loader) {
            $loader = new ArrayLoader();
        }

        return $loader->load($data);
    }

    private function getCacheFile(array $packageData)
    {
        return ComposerMirror::processUrl(
            $this->cacheDir.self::CACHE_FORMAT,
            $packageData['name'],
            $packageData['version_normalized'],
            !empty($packageData['dist']['reference']) ? $packageData['dist']['reference'] : null,
            $packageData['dist']['type']
        );
    }

    private function getProviderPath($package)
    {
        if (!file_exists($this->cacheDir . '/raw/packages.json')) {
            $this->getRootFile();
        }
        $root = json_decode(file_get_contents($this->cacheDir . '/raw/packages.json'), true);

        foreach ($root['provider-includes'] as $url => $meta) {
            $fileName = str_replace('%hash%', $meta['sha256'], $url);
            $url = $this->repoUrl.'/'.$fileName;
            $cacheFile = $this->cacheDir.'/raw/'.basename($url);

            if (file_exists($cacheFile)) {
                $contents = file_get_contents($cacheFile);
            } elseif ($contents = $this->getContents($url)) {
                file_put_contents($cacheFile, $contents);
                $this->cleanOldFiles($cacheFile);
            } else {
                return false;
            }

            $data = json_decode($contents, true);
            if (isset($data['providers'][$package])) {
                $this->config->addSyncedPackage($package);
                $this->config->save();

                return str_replace(
                    array('%package%', '%hash%'),
                    array($package, $data['providers'][$package]['sha256']),
                    $root['providers-url']
                );
            }
        }
    }

    private function cleanOldFiles($path)
    {
        // clean up old files
        $files = Finder::create()->files()->ignoreVCS(true)
            ->name('/'.preg_replace('{\$.*}', '', basename($path)).'\$[a-f0-9]+\.json$/')
            ->date('until 10minutes ago')
            ->in(dirname((string) $path));
        foreach ($files as $file) {
            unlink((string) $file);
        }
    }

    private function getOriginalNotifyUrl()
    {
        if (!$this->origNotifyUrl && function_exists('apc_fetch')) {
            $this->origNotifyUrl = \apc_fetch('notify_url_'.md5($this->repoUrl));
        }
        if (!$this->origNotifyUrl) {
            $contents = $this->getRootFile();
            if (!$contents) {
                throw new \RuntimeException('Could not load data from '.$this->repoUrl);
            }
            $root = json_decode($contents, true);
            if (isset($root['notify-batch'])) {
                if ('/' === $root['notify-batch'][0]) {
                    $this->origNotifyUrl = preg_replace('{(https?://[^/]+).*}i', '$1' . $root['notify-batch'], $this->repoUrl);
                } else {
                    $this->origNotifyUrl = $root['notify-batch'];
                }
            }
            if (function_exists('apc_store')) {
                \apc_store('notify_url_'.md5($this->repoUrl), $this->origNotifyUrl, 86400);
            }
        }

        return $this->origNotifyUrl;
    }

    private function getContents($url, array $opts = array(), $suppressFailures = true)
    {
        try {
            $host = parse_url($url, PHP_URL_HOST);
            if (preg_match('{\.github\.com$}i', $host)) {
                $host = 'github.com';
            }

            return $this->rfs->getContents($host, $url, false, $opts);
        } catch (\Exception $e) {
            if ($suppressFailures) {
                return;
            }

            throw $e;
        }
    }
}
