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

use Toran\ProxyBundle\Model\Repository;
use Symfony\Component\Yaml\Yaml;

class Configuration
{
    private $file;
    private $config;
    private $dirty = false;

    public function __construct($file)
    {
        if (file_exists($file)) {
            $this->config = Yaml::parse($file);
        } else {
            $this->config = array();
        }

        if (!empty($this->config['repositories'])) {
            foreach ($this->config['repositories'] as $idx => $repo) {
                $this->config['repositories'][$idx] = Repository::fromArray($repo, $idx);
            }
        } else {
            $this->config['repositories'] = array();
        }
        if (!isset($this->config['packagist_packages'])) {
            $this->config['packagist_packages'] = array();
        }

        $this->file = $file;
    }

    public function getRepositories()
    {
        return $this->config['repositories'];
    }

    public function getRepository($id, $digest)
    {
        if (isset($this->config['repositories'][$id]) && $this->config['repositories'][$id]->getDigest() === $digest) {
            return $this->config['repositories'][$id];
        }

        throw new \LogicException('Requested repository id '.$id.' with invalid digest '.$digest);
    }

    public function addRepository(Repository $repo)
    {
        $this->config['repositories'][] = $repo;
        $this->reindexRepositories();
        $this->dirty = true;
    }

    public function removeRepository(Repository $repo)
    {
        if (false !== ($idx = array_search($repo, $this->config['repositories'], true))) {
            unset($this->config['repositories'][$idx]);
            $this->reindexRepositories();
            $this->dirty = true;
        }
    }

    public function isGitSyncEnabled()
    {
        return $this->get('git_prefix') && $this->get('git_path');
    }

    public function get($key)
    {
        $res = isset($this->config[$key]) ? $this->config[$key] : false;

        switch ($key) {
            case 'git_prefix':
                // make sure it ends with a path delimiter (also : to allow prefixes like git@foo.bar:)
                if ($res && !preg_match('{[\\/:]$}', $res)) {
                    $res .= '/';
                }
                break;
            case 'dist_sync_mode':
                if (false === $res) {
                    $res = 'lazy';
                }
                break;
        }

        return $res;
    }

    public function set($key, $val)
    {
        $this->config[$key] = $val;
        $this->dirty = true;
    }

    public function addSyncedPackage($package)
    {
        if (!isset($this->config['packagist_packages'])) {
            $this->config['packagist_packages'] = array($package);
            $this->dirty = true;
        } elseif ($this->config['packagist_packages'] !== true && array_search($package, $this->config['packagist_packages'], true) === false) {
            $this->config['packagist_packages'][] = $package;
            $this->dirty = true;
        }
    }

    public function removeSyncedPackage($package)
    {
        if (false !== ($idx = array_search($package, $this->config['packagist_packages'], true))) {
            unset($this->config['packagist_packages'][$idx]);
            $this->config['packagist_packages'] = array_values($this->config['packagist_packages']);
            $this->dirty = true;
        }
    }

    public function getSyncedPackages()
    {
        return $this->config['packagist_packages'];
    }

    public function save($force = false)
    {
        if (!$this->dirty && !$force) {
            return;
        }

        $config = $this->config;
        foreach ($config['repositories'] as $idx => $repo) {
            $config['repositories'][$idx] = $repo->config;
        }
        if (isset($config['packagist_packages']) && is_array($config['packagist_packages'])) {
            $config['packagist_packages'] = array_unique($config['packagist_packages']);
        }

        if (!file_put_contents($this->file, Yaml::dump($config))) {
            if (!is_writable($this->file)) {
                throw new \RuntimeException('Unable to write the config into ' . $this->file . ' (permissions are incorrect)');
            }
            throw new \RuntimeException('Unable to write the config into ' . $this->file);
        }
        $this->dirty = false;
    }

    private function reindexRepositories()
    {
        $this->config['repositories'] = array_values($this->config['repositories']);
        foreach ($this->config['repositories'] as $idx => $repo) {
            $repo->id = $idx;
        }
    }
}
