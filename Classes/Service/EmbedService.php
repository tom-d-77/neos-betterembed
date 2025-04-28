<?php

namespace BetterEmbed\NeosEmbed\Service;

use BetterEmbed\NeosEmbed\Domain\Repository\BetterEmbedRepository;
use GuzzleHttp\Exception\GuzzleException;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use BetterEmbed\NeosEmbed\Domain\Dto\BetterEmbedRecord;
use Doctrine\Common\Collections\ArrayCollection;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeTemplate;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Exception;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Utility\Algorithms;
use GuzzleHttp\Client;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Strategy\AssetModelMappingStrategyInterface;
use Neos\Neos\Domain\Service\NodeSearchService;

/**
 *
 * @Flow\Scope("singleton")
 */
class EmbedService
{

    /**
     * @var \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub
     */
    protected $context;

    /**
     * @Flow\Inject
     * @var NodeService
     */
    protected $nodeService;


    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var AssetModelMappingStrategyInterface
     */
    protected $mappingStrategy;

    /**
     * @var ArrayCollection
     */
    protected $assetCollections;

    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;


    public function initializeObject()
    {
        $this->context = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub(['workspaceName' => 'live']);
        $this->assetCollections = new ArrayCollection([$this->nodeService->findOrCreateBetterEmbedAssetCollection()]);
    }

    /**
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node
     * @param Workspace|null $targetWorkspace
     * @throws GuzzleException
     * @throws NodeException
     * @throws NodeTypeNotFoundException
     */
    public function nodeUpdated(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node): void
    {
        // TODO 9.0 migration: Try to remove the toLegacyDimensionArray() call and make your codebase more typesafe.

        $this->context = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub([
            'workspaceName' => 'live',
            'dimensions' => $node->originDimensionSpacePoint->toLegacyDimensionArray()
        ]);
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        if ($contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName)->isOfType('BetterEmbed.NeosEmbed:Mixin.Item')) {
            $url = $node->getProperty('url');

            if (!empty($url)) {
                $recordNode = $this->getByUrl($url, true);
                // TODO 9.0 migration: !! Node::setProperty() is not supported by the new CR. Use the "SetNodeProperties" command to change property values.

                $node->setProperty('record', $recordNode);
            }
        }
    }

    /**
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node
     * @param Workspace|null $targetWorkspace
     * @throws GuzzleException
     * @throws NodeException
     * @throws NodeTypeNotFoundException
     */
    public function nodeRemoved(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node): void
    {

        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        if ($contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName)->isOfType('BetterEmbed.NeosEmbed:Mixin.Item')) {
            $url = $node->getProperty('url');
            // TODO 9.0 migration: The replacement needs a node as starting point for the search. Please provide a node, to make this replacement working.
            $node = 'we-need-a-node-here';
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);

            if (!empty($url) && count($subgraph->findDescendantNodes($node->aggregateId, \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter::create(nodeTypes: \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria::create(\Neos\ContentRepository\Core\NodeType\NodeTypeNames::fromStringArray(['BetterEmbed.NeosEmbed:Mixin.Item']), \Neos\ContentRepository\Core\NodeType\NodeTypeNames::createEmpty()), searchTerm: ['url' => str_replace('"', '', json_encode($url))]))) <= 1) {
                $recordNode = $this->getByUrl($url);
                if ($recordNode) {
                    $this->nodeService->removeEmbedNode($recordNode);
                }
            }
        }
    }

    /**
     * @param string $url
     * @param bool $createIfNotFound
     * @return \Neos\ContentRepository\Core\Projection\ContentGraph\Node|null
     * @throws Exception
     * @throws GuzzleException
     * @throws NodeTypeNotFoundException
     * @throws \Neos\Eel\Exception
     */
    public function getByUrl(string $url, $createIfNotFound = false)
    {
        /** @var \Neos\ContentRepository\Core\Projection\ContentGraph\Node $record */
        $node = $this->nodeService->findRecordByUrl($this->context->getNode('/' . BetterEmbedRepository::BETTER_EMBED_ROOT_NODE_NAME), $url);

        if ($node == null && $createIfNotFound) {

            $urlParts = parse_url($url);

            if (strstr($urlParts['host'], 'facebook')) {
                throw new Exception('Facebook URLs are not supported due GDPR consent gateway protection.');
            }

            if (strstr($urlParts['host'], 'instagram')) {
                throw new Exception('Instagram URLs are not supported due GDPR consent gateway protection.');
            }

            $record = $this->callService($url);
            $node = $this->createRecordNode($record);
        }

        return $node;
    }


    /**
     * @param string $url
     * @return BetterEmbedRecord
     * @throws GuzzleException
     * @throws \Exception
     */
    private function callService(string $url)
    {

        $client = new Client();
        $response = $client->request(
            'GET',
            'https://api.betterembed.com/api/v0/item',
            ['query' => ['url' => $url]]
        );

        $response = new BetterEmbedRecord(json_decode((string) $response->getBody()));

        return $response;
    }

    /**
     * @param BetterEmbedRecord $record
     * @return \Neos\ContentRepository\Core\Projection\ContentGraph\Node
     * @throws NodeTypeNotFoundException
     */
    private function createRecordNode(BetterEmbedRecord $record)
    {

        $assetOriginal = $record->getThumbnailUrl(); //original asset may have get parameters in the url
        $asset = preg_replace('/(^.*\.(jpg|jpeg|png|gif)).*$/', '$1', $assetOriginal); //asset witout get parametes for neos import
        $extension = preg_replace('/^.*\.(jpg|jpeg|png|gif)$/', '$1', $asset); // asset extension

        $image = null;
        if (filter_var($assetOriginal, FILTER_VALIDATE_URL)) {
            // If the $asset is the same as $extension then there was no matching extension in this case use the mime type defined by hosting server
            if ($asset === $extension) {
                $client = new Client();
                $mimeType = $client->head($asset)->getHeader('Content-Type')[0];
                $extension = str_replace('image/', '', str_replace('x-', '', $mimeType)); // account for image/png and image/x-png mimeTypes
            } else {
                $mimeType = 'image/' . $extension;
            }

            $resource = $this->resourceManager->importResource($assetOriginal);
            $tags = new ArrayCollection([$this->nodeService->findOrCreateBetterEmbedTag($record->getItemType(), $this->assetCollections)]);

            /** @var Image $image */
            $image = $this->assetRepository->findOneByResourceSha1($resource->getSha1());
            if ($image === null) {
                $image = new Image($resource);
                $image->getResource()->setFilename(md5($record->getUrl()) . '.' . $extension);
                $image->getResource()->setMediaType($mimeType);
                $image->setAssetCollections($this->assetCollections);
                $image->setTags($tags);
                $this->assetRepository->add($image);
            }
        }
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.
        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));

        /** @var \Neos\ContentRepository\Core\NodeType\NodeType $nodeType */
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType('BetterEmbed.NeosEmbed:Record');
        // TODO 9.0 migration: !! NodeTemplate is removed in Neos 9.0. Use the "CreateNodeAggregateWithNode" command to create new nodes or "CreateNodeVariant" command to create variants of an existing node in other dimensions.


        /** @var NodeTemplate $nodeTemplate */
        $nodeTemplate = new NodeTemplate();
        $nodeTemplate->setNodeType($nodeType);
        // TODO 9.0 migration: !! NodeTemplate::setName is removed in Neos 9.0. Use the "CreateNodeAggregateWithNode" command to create new nodes or "CreateNodeVariant" command to create variants of an existing node in other dimensions.

        $nodeTemplate->setName(Algorithms::generateUUID());
        $nodeTemplate->setProperty('url', $record->getUrl());
        $nodeTemplate->setProperty('itemType', $record->getItemType());
        $nodeTemplate->setProperty('title', $record->getTitle());
        $nodeTemplate->setProperty('body', $record->getBody());
        $nodeTemplate->setProperty('thumbnailUrl', $record->getThumbnailUrl());
        $nodeTemplate->setProperty('thumbnailContentType', $record->getThumbnailContentType());
        $nodeTemplate->setProperty('thumbnailContent', $record->getThumbnailContent());
        $nodeTemplate->setProperty('thumbnail', $image);
        $nodeTemplate->setProperty('embedHtml', $record->getEmbedHtml());
        $nodeTemplate->setProperty('authorName', $record->getAuthorName());
        $nodeTemplate->setProperty('authorUrl', $record->getAuthorUrl());
        $nodeTemplate->setProperty('authorImage', $record->getAuthorImage());
        $nodeTemplate->setProperty('publishedAt', $record->getPublishedAt());
        $nodeTemplate->setProperty('uriPathSegment', 'embed-' . random_int(0000000000, 9999999999));

        /** @var \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node */
        $node = $this->nodeService->findOrCreateBetterEmbedRootNode($this->context)->createNodeFromTemplate($nodeTemplate);

        return $node;
    }
}
