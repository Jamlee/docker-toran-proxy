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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\ErrorHandler;
use Composer\Util\RemoteFilesystem;
use Composer\IO\ConsoleIO;
use Composer\Factory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class UpdateCommand extends ContainerAwareCommand
{
    private $perms = array();

    protected function configure()
    {
        $this
            ->setName('toran:update')
            ->setDescription('Updates Toran to the latest available version')
            ->setDefinition(array(
            ))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new ConsoleIO($input, $output, $this->getHelperSet());
        $composerConfig = Factory::createConfig();
        $io->loadConfiguration($composerConfig);

        $baseDir = strtr(realpath($this->getContainer()->getParameter('kernel.root_dir').'/../'), '\\', '/');
        $rfs = new RemoteFilesystem($io, $composerConfig);

        $newVersion = $rfs->getContents('toranproxy.com', 'https://toranproxy.com/version', false);
        $currentVersion = $this->getContainer()->getParameter('toran_version');

        if ($currentVersion === $newVersion) {
            $output->writeln('Already up to date');
            return 0;
        }

        if (!is_writable($baseDir) || !is_writable($baseDir.'/src')) {
            $perms = fileperms($baseDir);
            if (true !== @chmod($baseDir, $perms & 0777 | 0700)) {
                $output->getErrorOutput()->writeln('<error>The auto-updater needs to be run with the user owning '.$baseDir.' or that directory should at least be writable by the current user</error>');
                return 1;
            }
            chmod($baseDir, $perms);
        }

        set_time_limit(0);
        ErrorHandler::register();

        $exec = new ProcessExecutor($io);
        $fs = new Filesystem($exec);

        $baseUpdateDir = $baseDir.'/temp-update';
        $this->remove($baseUpdateDir);
        $this->acquirePerms($baseDir);
        mkdir($baseUpdateDir);
        $output->writeln('Downloading new version: '.$newVersion);
        $rfs->copy('toranproxy.com', 'https://toranproxy.com/releases/toran-proxy-v'.$newVersion.'.tgz', $baseUpdateDir.'/new.tgz', false);
        $output->writeln('Decompressing...');
        if (0 !== $exec->execute('tar --strip-components=1 -zxf new.tgz', $ignoredOutput, $baseUpdateDir)) {
            $output->getErrorOutput()->writeln('<error>Could not untar the new release: '.$exec->getErrorOutput());
            return 2;
        }
        unlink($baseUpdateDir.'/new.tgz');

        $output->writeln('Updating');

        $paths = array(
            $baseUpdateDir.'/bin' => $baseDir.'/bin',
            $baseUpdateDir.'/doc' => $baseDir.'/doc',
            $baseUpdateDir.'/src' => $baseDir.'/src',
            $baseUpdateDir.'/vendor' => $baseDir.'/vendor',
        );

        foreach ($paths as $from => $to) {
            $this->acquirePerms($to);

            if ($io->isVerbose()) {
                $output->writeln('renaming '.$to.' to '.$to.'-old');
            }
            rename($to, $to.'-old');

            if ($io->isVerbose()) {
                $output->writeln('renaming '.$from.' to '.$to);
            }
            rename($from, $to);

            if ($io->isVerbose()) {
                $output->writeln('deleting '.$to.'-old');
            }
            $this->remove($to.'-old');
        }

        $finder = Finder::create()
            ->ignoreVCS(false)
            ->ignoreDotFiles(false)
            ->depth(0)
            ->files()
            ->in($baseUpdateDir);

        foreach ($finder as $file) {
            $target = $baseDir.'/'.$file->getBaseName();
            $this->acquirePerms($target);
            $this->acquirePerms(dirname($target));
            if ($io->isVerbose()) {
                $output->writeln('renaming '.$file.' to '.$target);
            }
            rename($file, $target);
        }

        $finder = Finder::create()
            ->ignoreVCS(false)
            ->ignoreDotFiles(false)
            ->in(array($baseUpdateDir.'/web', $baseUpdateDir.'/app'));

        foreach ($finder as $file) {
            $path = strtr($file->getPathName(), '\\', '/');
            $target = str_replace($baseUpdateDir, $baseDir, $path);

            if (is_dir($path)) {
                if ($io->isVerbose()) {
                    $output->writeln('ensuring '.$target.' exists');
                }
                $this->acquirePerms(dirname($target));
                $fs->ensureDirectoryExists($target);
            } else {
                if ($io->isVerbose()) {
                    $output->writeln('renaming '.$path.' to '.$target);
                }
                $this->acquirePerms($target);
                $this->acquirePerms(dirname($target));
                rename($path, $target);
            }
        }

        $this->releasePerms();

        $output->writeln('Clearing application cache');

        $this->remove($baseDir.'/app/cache/prod');
        $this->remove($baseDir.'/app/cache/dev');

        $this->remove($baseUpdateDir);

        $output->writeln('Done!');
    }

    private function remove($path)
    {
        if (!file_exists($path)) {
            return;
        }

        $this->acquirePerms($path, false);
        $this->acquirePerms(dirname($path));

        if (is_file($path)) {
            unlink($path);
            return;
        }

        $it = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($ri as $file) {
            $this->acquirePerms($file->getPathname(), false);
            $this->acquirePerms(dirname($file->getPathname()));
            if ($file->isDir()) {
                rmdir($file->getPathname());
                // remove storage of the dir's perms in case it was stored when deleting a file within
                unset($this->perms[$file->getPathname()]);
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }

    private function acquirePerms($path, $store = true)
    {
        if (!isset($this->perms[$path]) && file_exists($path) && !is_writable($path)) {
            $perms = fileperms($path);
            if (!@chmod($path, $perms & 0777 | 0700)) {
                throw new \RuntimeException('Could not change permissions of '.$path);
            }
            if ($store) {
                $this->perms[$path] = $perms;
            }
            clearstatcache();
            if (!is_writable($path)) {
                $this->acquirePerms(dirname($path));
            }
        }
    }

    private function releasePerms()
    {
        clearstatcache();
        foreach ($this->perms as $path => $perms) {
            if (file_exists($path)) {
                @chmod($path, $perms);
            }
        }
    }
}
