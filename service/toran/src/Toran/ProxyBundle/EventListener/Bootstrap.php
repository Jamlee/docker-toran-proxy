<?php

/*
 * This file is part of the Toran package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Toran\ProxyBundle\EventListener;

use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Twig_Environment;
use Toran\ProxyBundle\Service\Util;
use Composer\Util\RemoteFilesystem;
use Composer\IO\NullIO;
use Composer\Factory;

class Bootstrap
{
    protected $util;
    protected $composerHome;
    protected $composerCacheDir;
    protected $cacheDir;
    protected $twig;
    protected $currentVersion;

    public function __construct(Util $util, $composerHome, $composerCacheDir, $cacheDir, Twig_Environment $twig, $currentVersion)
    {
        $this->util = $util;
        $this->composerHome = $composerHome;
        $this->composerCacheDir = $composerCacheDir;
        $this->cacheDir = $cacheDir;
        $this->twig = $twig;
        $this->currentVersion = $currentVersion;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        putenv('COMPOSER_HOME='.$this->composerHome);
        putenv('COMPOSER_CACHE_DIR='.$this->composerCacheDir);

        $latestVersionCache = $this->cacheDir.'/latest_version';
        $fetch = true;
        if (file_exists($latestVersionCache)) {
            $params = explode('|', trim(file_get_contents($latestVersionCache)));
            if (count($params) == 2 && $params[0] > date('Y-m-d H:i:s')) {
                $fetch = false;
                $version = $params[1];
            }
        }

        $product = $this->util->getProductName();
        if ($fetch) {
            $io = new NullIO();
            $composerConfig = Factory::createConfig();
            $io->loadConfiguration($composerConfig);
            $rfs = new RemoteFilesystem($io, $composerConfig);

            try {
                $version = $rfs->getContents('toranproxy.com', 'https://toranproxy.com/version?product='.$product, false) ?: $this->currentVersion;
                file_put_contents($latestVersionCache, date('Y-m-d H:i:s', time() + 86400).'|'.$version);
            } catch (\Exception $e) {
            }
        }

        $this->twig->addGlobal('latest_toran_version', $version);
        $this->twig->addGlobal('toran_product', $product);
    }
}
