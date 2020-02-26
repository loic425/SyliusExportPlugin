<?php

/*
 * This file is part of rd_082_s_sylius_export_plugin.
 *
 * (c) Mobizel.com
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mobizel\SyliusExportPlugin\Controller;

use Mobizel\SyliusExportPlugin\Exporter\ExporterInterface;
use Pagerfanta\Pagerfanta;
use Sylius\Bundle\ResourceBundle\Controller\AuthorizationCheckerInterface;
use Sylius\Bundle\ResourceBundle\Controller\EventDispatcherInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfigurationFactoryInterface;
use Sylius\Bundle\ResourceBundle\Controller\ResourcesCollectionProviderInterface;
use Sylius\Bundle\ResourceBundle\Grid\View\ResourceGridView;
use Sylius\Component\Resource\Metadata\MetadataInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @author Kévin Régnier <kevin@mobizel.com>
 */
class BulkExportAction
{
    /** @var MetadataInterface */
    protected $metadata;

    /** @var RequestConfigurationFactoryInterface */
    protected $requestConfigurationFactory;

    /** @var RepositoryInterface */
    protected $repository;

    /** @var ResourcesCollectionProviderInterface */
    protected $resourcesCollectionProvider;

    /** @var EventDispatcherInterface */
    protected $eventDispatcher;

    /** @var AuthorizationCheckerInterface */
    protected $authorizationChecker;

    /** @var ExporterInterface */
    private $exporter;

    public function __construct(
        MetadataInterface  $metadata,
        RequestConfigurationFactoryInterface $requestConfigurationFactory,
        RepositoryInterface $repository,
        ResourcesCollectionProviderInterface $resourcesCollectionProvider,
        EventDispatcherInterface $eventDispatcher,
        AuthorizationCheckerInterface $authorizationChecker,
        ExporterInterface $exporter
    ) {
        $this->metadata = $metadata;
        $this->requestConfigurationFactory = $requestConfigurationFactory;
        $this->repository = $repository;
        $this->resourcesCollectionProvider = $resourcesCollectionProvider;
        $this->eventDispatcher = $eventDispatcher;
        $this->authorizationChecker = $authorizationChecker;
        $this->exporter = $exporter;
    }

    public function __invoke(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, ResourceActions::BULK_EXPORT);
        $resources = $this->resourcesCollectionProvider->get($configuration, $this->repository);

        $this->eventDispatcher->dispatchMultiple('bulk_export', $configuration, $resources);

        $fileName = sprintf('%s.%s.csv', 'export_commandes', (new \DateTime())->format('d-m-Y_H-m'));

        /** @var Pagerfanta $paginator */
        if ($resources instanceof ResourceGridView) {
            $paginator = $resources->getData();
        } else {
            $paginator = $resources;
        }

        if ($paginator->getNbPages() > 1) {
            $nbResult = $paginator->count();
            $paginator->setMaxPerPage($nbResult);
        }

        $fileContent = $this->exporter->export($resources);

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$fileName.'"');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Content-Transfer-Encoding', 'binary');
        $response->setContent($fileContent);

        return $response;
    }


    /**
     * @throws AccessDeniedException
     */
    protected function isGrantedOr403(RequestConfiguration $configuration, string $permission): void
    {
        if (!$configuration->hasPermission()) {
            return;
        }

        $permission = $configuration->getPermission($permission);

        if (!$this->authorizationChecker->isGranted($configuration, $permission)) {
            throw new AccessDeniedException();
        }
    }
}
