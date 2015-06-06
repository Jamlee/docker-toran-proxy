<?php

/*
 * This file is part of the Toran package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Toran\ProxyBundle\Command;

use Composer\Downloader\FileDownloader;
use Composer\IO\NullIO;
use Composer\IO\ConsoleIO;
use Composer\Package\AliasPackage;
use Composer\Config as ComposerConfig;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Util\ComposerMirror;
use Toran\ProxyBundle\Service\Proxy;
use Toran\ProxyBundle\Service\Configuration;
use Toran\ProxyBundle\Service\Util;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

class CronCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('toran:cron')
            ->setDescription('Runs background jobs')
            ->setDefinition(array(
            ))
            ->setHelp(<<<EOT
Runs periodic background jobs of Toran Proxy

Run this command with --verbose first to initialize credentials
and then set up a cron job running it every minute with
--no-interaction

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cacheDir = $this->getContainer()->getParameter('kernel.cache_dir');
        $lock = $cacheDir.'/cron.lock';
        $isVerbose = (bool) $input->getOption('verbose');

        // verify writability
        $paths = array(
            $cacheDir => false,
            realpath($this->getContainer()->getParameter('toran_web_dir')).'/repo/private' => false,
            $this->getContainer()->getParameter('toran_cache_dir') => false,
            $this->getContainer()->getParameter('composer_home') => false,
            $this->getContainer()->getParameter('composer_cache_dir') => false,
        );
        foreach ($paths as $path => &$unwritable) {
            $unwritable = is_dir($path) && !is_writable($path);
        }
        if ($paths = array_keys(array_filter($paths))) {
            $output->writeln('The following directories are not writable, make sure you run bin/cron with the web user and then wipe them or make sure they are owned by the web user: ' . implode(', ', $paths));
            return 1;
        }

        // init composer config on first run
        $composerConfig = new JsonFile($this->getContainer()->getParameter('composer_home').'/config.json');
        if (!$composerConfig->exists()) {
            $isVerbose = true;
            $content = array('config' => array('github-protocols' => array('https', 'git', 'ssh')));
            $composerConfig->write($content);
        }
        $composerAuthConfig = new JsonFile($this->getContainer()->getParameter('composer_home').'/auth.json');
        if (!$composerAuthConfig->exists()) {
            $isVerbose = true;
            $output->writeln('You need to setup a GitHub OAuth token because Toran makes a lot of requests and will use up the API calls limit if it is unauthenticated');
            $output->writeln('Head to https://github.com/settings/tokens/new to create a token. You need to select the public_repo credentials, and the repo one if you are going to use private repositories from GitHub with Toran.');

            $dialog = $this->getHelperSet()->get('dialog');
            $token = $dialog->ask($output, 'Token: ', null);

            if ($token) {
                $content = array();
                $content['github-oauth'] = array('github.com' => $token);
                $composerAuthConfig->write($content);
            } else {
                file_put_contents($composerAuthConfig->getPath(), '{}');
            }
        }

        // another job is still active
        if (file_exists($lock)) {
            // check if the process is still running if we have a valid pid in the lock
            if (!file_exists('/proc') || file_get_contents($lock) === '' || file_exists('/proc/'.file_get_contents($lock))) {
                // check if it's less than 1h old
                if (filemtime($lock) > time() - 3600) {
                    if ($isVerbose) {
                        $output->writeln('Aborting, '.$lock.' file is present, a previous job is still running or may have died unexpectedly');
                    }
                    return;
                }
            }
            // assuming the other process crashed, restarting
        }

        ini_set('memory_limit', -1);
        set_time_limit(0);

        file_put_contents($lock, function_exists('getmypid') ? getmypid() : '');
        $this->syncPrivateRepositories($input, $isVerbose ? $output : null);
        file_put_contents($lock, function_exists('getmypid') ? getmypid() : '');
        $this->syncPackagistPackages($input, $isVerbose ? $output : null);
        unlink($lock);

        return 0;
    }

    private function syncPackagistPackages(InputInterface $input, OutputInterface $output = null)
    {
        $toranConfig = $this->getContainer()->get('config');
        if ('proxy' !== $toranConfig->get('packagist_sync')) {
            return;
        }

        $config = Factory::createConfig();
        $io = $output ? new ConsoleIO($input, $output, $this->getHelperSet()) : new NullIO;
        $io->loadConfiguration($config);

        $proxy = $this->getContainer()->get('proxy_factory')->createProxy('packagist', $io, $config);

        if ($output) {
            $output->writeln("<info>Initializing packagist proxy repository</info>");
        }

        // initialize
        $proxy->getRootFile();

        $distSyncMode = $toranConfig->get('dist_sync_mode');
        foreach ($toranConfig->getSyncedPackages() as $package) {
            if ($output) {
                $output->writeln("<info>Synchronizing dist archives and clone for $package</info>");
            }
            // getProviderFile already syncs git repos implicitly if the file is new
            if (($data = $proxy->getProviderFile($package, $io, $config)) && $distSyncMode !== 'lazy') {
                $data = json_decode($data, true);
                foreach ($data['packages'] as $name => $versions) {
                    if ($name !== $package) {
                        continue;
                    }

                    $versions = Util::sortByVersion($versions);
                    $syncDists = $distSyncMode === 'all';

                    foreach ($versions as $versionData) {
                        if ($versionData['type'] === 'metapackage' || empty($versionData['dist']['url'])) {
                            continue;
                        }

                        if ($syncDists) {
                            $proxy->downloadPackage($versionData);
                        } elseif ($distSyncMode === 'new' && false === strpos($versionData['version_normalized'], 'dev') && $proxy->hasPackageInCache($versionData)) {
                            // non-dev releases that are synced trigger a sync of all newer releases if distSyncMode is 'new'
                            $syncDists = true;
                        }
                    }
                }
            }
        }

        // TODO MED clean up old provider listings in cache/packagist/{p,raw}
    }

    private function syncPrivateRepositories(InputInterface $input, OutputInterface $output = null)
    {
        $toranConfig = $this->getContainer()->get('config');

        $repoSyncer = $this->getContainer()->get('repo_syncer');
        $io = $output ? new ConsoleIO($input, $output, $this->getHelperSet()) : new NullIO;
        $repoSyncer->sync($io, $toranConfig->getRepositories(), true);
    }
}
