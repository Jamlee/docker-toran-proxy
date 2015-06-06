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
use Symfony\Component\HttpFoundation\JsonResponse;
use Toran\ProxyBundle\Model\Repository;
use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Form\FormError;

class RepoController extends Controller
{
    const GITHUB_URL_RE = '{^(?:https?://|git://|ssh://)?(?:[a-zA-Z0-9_\-]+@)?(?P<host>[a-z0-9.-]+)(?::(?:\d+/)?|/)(?P<path>[\w.\-/]+?)(?:\.git|/)?$}';
    const BITBUCKET_URL_RE = '{^(?:https?://|git://|git@)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';

    public function indexAction()
    {
        return $this->render('ToranProxyBundle:Repo:index.html.twig', array(
            'repos' => $this->get('config')->getRepositories()
        ));
    }

    public function createAction(Request $req)
    {
        $config = $this->get('config');
        $repo = new Repository;
        $builder = $this->createRepoForm($req);
        $builder->add('Create', 'submit');
        $form = $builder->getForm();

        if ($req->getMethod() === 'POST') {
            $form->bind($req);
            if ($this->processForm($form, $repo, $config)) {
                return $this->redirect($this->generateUrl('toran_proxy_repo_index'));
            }
        }

        return $this->render('ToranProxyBundle:Repo:create.html.twig', array('form' => $form->createView()));
    }

    public function editAction(Request $req, $id, $digest)
    {
        $config = $this->get('config');
        $repo = $config->getRepository($id, $digest);
        $builder = $this->createRepoForm($req, $repo);
        $builder->add('Save', 'submit');
        $form = $builder->getForm();

        if ($req->getMethod() === 'POST') {
            $form->bind($req);
            if ($this->processForm($form, $repo, $config)) {
                return $this->redirect($this->generateUrl('toran_proxy_repo_index'));
            }
        }

        return $this->render('ToranProxyBundle:Repo:edit.html.twig', array('form' => $form->createView()));
    }

    public function deleteAction(Request $req, $id, $digest)
    {
        $config = $this->get('config');

        try {
            $repo = $config->getRepository($id, $digest);
        } catch (\Exception $e) {
            return $this->redirect($this->generateUrl('toran_proxy_repo_index'));
        }

        $config->removeRepository($repo);
        $config->save();

        return $this->redirect($this->generateUrl('toran_proxy_repo_index'));
    }

    public function hookAction(Request $req)
    {
        $payload = json_decode($req->request->get('payload'), true);
        if (!$payload && $req->headers->get('Content-Type') === 'application/json') {
            $payload = json_decode($req->getContent(), true);
        }

        if (!$payload) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Missing payload parameter'), 406);
        }

        if (isset($payload['repository']['url'])) { // github/gitlab/anything hook
            $urlRegex = self::GITHUB_URL_RE;
            $url = $payload['repository']['url'];
        } elseif (isset($payload['canon_url']) && isset($payload['repository']['absolute_url'])) { // bitbucket hook
            $urlRegex = self::BITBUCKET_URL_RE;
            $url = $payload['canon_url'].$payload['repository']['absolute_url'];
        } else {
            return new JsonResponse(array('status' => 'error', 'message' => 'Missing or invalid payload'), 406);
        }

        if (!preg_match($urlRegex, $url)) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Could not parse repository URL in payload'), 406);
        }

        // try to find the user package
        $repository = $this->findRepositoryByUrl($url, $urlRegex);

        if (!$repository) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Could not find a repository that matches this request'), 404);
        }

        set_time_limit(3600);

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);

        try {
            $repoSyncer = $this->get('repo_syncer');
            $repoSyncer->sync($io, array($repository));
        } catch (\Exception $e) {
            return new JsonResponse(array(
                'status' => 'error',
                'message' => '['.get_class($e).'] '.$e->getMessage(),
                'details' => '<pre>'.$io->getOutput().'</pre>'
            ), 400);
        }

        return new JsonResponse(array('status' => 'success'), 202);
    }

    public function render($view, array $parameters = array(), Response $response = null)
    {
        if (!isset($parameters['page'])) {
            $parameters['page'] = 'private';
        }

        return parent::render($view, $parameters, $response);
    }

    /**
     * Find a repository given its full URL
     *
     * @param string $url
     * @param string $urlRegex
     * @return Repository|null the found repository or null otherwise
     */
    protected function findRepositoryByUrl($url, $urlRegex)
    {
        if (!preg_match($urlRegex, $url, $matched)) {
            return null;
        }

        $config = $this->get('config');
        foreach ($config->getRepositories() as $repo) {
            if (preg_match($urlRegex, $repo->url, $candidate)
                && $candidate['host'] === $matched['host']
                && $candidate['path'] === $matched['path']
            ) {
                return $repo;
            }
        }

        return null;
    }

    protected function createRepoForm(Request $req, Repository $repo = null)
    {
        $repo = $repo ?: new Repository();
        $data = array(
            'type' => $repo->type,
            'url' => $repo->url,
            'package' => isset($repo->config['package']) ? JsonFile::encode($repo->config['package']) : '',
        );

        $form = $this->createFormBuilder($data)
            ->add('type', 'choice', array(
                'required' => true,
                'label' => 'Type (Use VCS for github/bitbucket/git/svn/hg repositories unless you have a good reason not to)',
                'choices' => array('vcs' => 'vcs', 'git' => 'git', 'hg' => 'hg', 'svn' => 'svn', 'artifact' => 'artifact', 'pear' => 'pear', 'package' => 'package'),
            ))
            ->add('url', 'text', array(
                'required' => false,
                'label' => 'Repository URL or path (bitbucket git repositories need the trailing .git)'
            ))
            ->add('package', 'textarea', array(
                'required' => false,
                'label' => 'JSON package definition (package repositories only)'
            ))
        ;

        return $form;
    }

    protected function processForm($form, $repo, $config)
    {
        if (!count($form->getErrors())) {
            $data = $form->getData();
            if ($data['type'] === 'package') {
                unset($data['url']);
                try {
                    $data['package'] = JsonFile::parseJson($data['package'], 'package');
                } catch (\Exception $e) {
                    $form->get('package')->addError(new FormError('<pre>'.$e->getMessage().'</pre>'));
                    return false;
                }
            } else {
                unset($data['package']);
            }

            $repo->config = $data;
            if (null === $repo->id) {
                $config->addRepository($repo);
            }
            $config->save(true);

            return true;
        }
    }
}
