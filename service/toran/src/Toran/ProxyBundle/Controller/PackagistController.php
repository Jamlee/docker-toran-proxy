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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Composer\Util\RemoteFilesystem;
use Composer\IO\NullIO;
use Composer\Factory;

class PackagistController extends Controller
{
    public function indexAction()
    {
        $syncedPackages = $this->get('config')->getSyncedPackages();
        sort($syncedPackages);

        return $this->render('ToranProxyBundle:Packagist:index.html.twig', array(
            'packages' => $syncedPackages,
            'is_enabled' => 'proxy' === $this->get('config')->get('packagist_sync')
        ));
    }

    public function addAction(Request $req)
    {
        $data = array('packages' => '');
        $form = $this->createFormBuilder($data)
            ->add('packages', 'textarea', array(
                'required' => false,
                'label' => 'Packages to start synchronizing (one per line)'
            ))
            ->add('Add', 'submit')
            ->getForm();

        if ($req->getMethod() === 'POST') {
            $form->bind($req);
            $data = $form->getData();
            if ($form->isValid()) {
                $skipped = array();
                if (trim($data['packages'])) {
                    $io = new NullIO();
                    $composerConfig = Factory::createConfig();
                    $io->loadConfiguration($composerConfig);
                    $rfs = new RemoteFilesystem($io, $composerConfig);

                    $validPackages = json_decode($rfs->getContents('packagist.org', 'https://packagist.org/packages/list.json', false), true);
                    $config = $this->get('config');
                    foreach (preg_split('{(\r?\n)+}', trim($data['packages'])) as $package) {
                        if (in_array($package, $validPackages['packageNames'], true)) {
                            $config->addSyncedPackage($package);
                        } else {
                            $skipped[] = $package;
                        }
                    }
                    $config->save();
                }

                if ($skipped) {
                    $this->get('session')->getFlashBag()->add('warning', 'The following packages were not found on packagist and were skipped: '.implode(', ', $skipped));
                } else {
                    $this->get('session')->getFlashBag()->add('success', 'Packages added successfully');
                }

                return $this->redirect($this->generateUrl('toran_proxy_packagist_index'));
            }
        }

        return $this->render('ToranProxyBundle:Packagist:add.html.twig', array('form' => $form->createView()));
    }

    public function deleteAction(Request $req, $package)
    {
        $proxy = $this->get('proxy_factory')->createProxy('packagist');
        $proxy->removePackage($package);

        $this->get('session')->getFlashBag()->add('success', $package.' successfully removed');

        return $this->redirect($this->generateUrl('toran_proxy_packagist_index'));
    }

    public function render($view, array $parameters = array(), Response $response = null)
    {
        if (!isset($parameters['page'])) {
            $parameters['page'] = 'packagist';
        }

        return parent::render($view, $parameters, $response);
    }
}
