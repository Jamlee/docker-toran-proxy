<?php

/*
 * This file is part of the Toran package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Toran\ProxyBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Composer\Downloader\FileDownloader;
use Composer\Util\RemoteFilesystem;
use Composer\IO\NullIO;
use Composer\IO\BufferIO;
use Composer\Factory;
use Toran\ProxyBundle\Service\Proxy;

class ProxyController extends Controller
{
    public function rootAction(Request $req, $repo)
    {
        try {
            // TODO LOW figure out a way to handle multiple repos as proxies, or at least have private packages + packagist proxy
            $proxy = $this->getProxy($repo);
            if ($proxy instanceof Response) {
                return $proxy;
            }
            return new Response($proxy->getRootFile(), 200, array('Content-Type' => 'application/json'));
        } catch (HttpException $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            return new Response($e->getMessage(), 500);
        }
    }

    public function distAction(Request $req, $repo, $name, $version, $ref, $type)
    {
        if ($repo == 'private') {
            $cacheFile = $this->get('repo_syncer')->getDistFilename($req, $name, $version, $ref, $type);
        } else {
            $cacheFile = $this->getProxy($repo)->getDistFilename($name, $version, $ref, $type);
        }

        if ('' === $cacheFile) {
            return new Response('This dist file can not be found nor downloaded from the original url', 404);
        }

        return new BinaryFileResponse($cacheFile, 200, array(), false);
    }

    public function providerAction($repo, $filename)
    {
        $io = new BufferIO;
        $proxy = $this->getProxy($repo);
        if ($proxy instanceof Response) {
            return $proxy;
        }
        $contents = $proxy->getProviderFile($filename, $io);

        if ($output = $io->getOutput()) {
            $this->get('logger')->error('Failure while syncing '.$filename.': '.$output);
        }

        if (false === $contents) {
            return new Response('Not Found', 404);
        }

        return new Response($contents, 200, array('Content-Type' => 'application/json'));
    }

    protected function getProxy($repo)
    {
        if ($repo === 'private') {
            $webDir = realpath($this->container->getParameter('toran_web_dir'));
            if (!file_exists($webDir.'/repo/private/packages.json')) {
                return new Response('This repository was not initialized, you should make sure you are running bin/cron regularly', 404);
            }

            $path = $this->get('request_stack')->getCurrentRequest()->getPathInfo();
            if (file_exists($webDir.$path)) {
                return new Response(file_get_contents($webDir.$path), 200, array('Content-Type' => 'application/json'));
            }

            return new Response('This repository is missing the file you are looking for', 404);
        }

        if ($repo === 'packagist' && 'proxy' !== $this->get('config')->get('packagist_sync')) {
            return new Response('This repository does not have packagist proxying enabled', 404);
        }

        return $this->get('proxy_factory')->createProxy($repo);
    }
}
