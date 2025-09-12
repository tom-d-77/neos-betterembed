<?php

namespace BetterEmbed\NeosEmbed\Service;

use BetterEmbed\NeosEmbed\Domain\Repository\BetterEmbedRepository;
use GuzzleHttp\Exception\GuzzleException;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
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
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;

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
        // $this->context = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub(['workspaceName' => 'live']);
        $this->assetCollections = new ArrayCollection([$this->nodeService->findOrCreateBetterEmbedAssetCollection()]);
    }

    /**
     * Resolve the "better-embeds" root as a child of the current site's root.
     * Falls back to null if it doesn’t exist.
     */
    private function getBetterEmbedRootFor(Node $fromNode): ?Node
    {
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($fromNode, VisibilityConstraints::withoutRestrictions());

        // 1) Resolve the site root (walk upwards until there is no parent)
        $current = $fromNode;
        while (true) {
            $parents = $subgraph->findParentNodes($current->aggregateId);
            if ($parents === [] || $parents === null) {
                $siteRoot = $current;
                break;
            }
            // pick the first parent (there’s exactly one in the common tree)
            $current = $parents[0];
        }

        // 2) Find the direct child named "better-embeds"
        $children = $subgraph->findChildNodes(
            $siteRoot->aggregateId,
            FindChildNodesFilter::create(
                nodeName: NodeName::fromString(\BetterEmbed\NeosEmbed\Domain\Repository\BetterEmbedRepository::BETTER_EMBED_ROOT_NODE_NAME)
            )
        );

        return $children[0] ?? null;
    }


    /**
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node
     * @throws GuzzleException
     * @throws NodeException
     * @throws NodeTypeNotFoundException
     */
    public function nodeUpdated(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node): void
    {
        // TODO 9.0 migration: Try to remove the toLegacyDimensionArray() call and make your codebase more typesafe.

        // $this->context = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub([
        //    'workspaceName' => 'live',
        //    'dimensions' => $node->originDimensionSpacePoint->toLegacyDimensionArray()
        // ]);

        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        if ($contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName)->isOfType('BetterEmbed.NeosEmbed:Mixin.Item')) {
            $url = $node->getProperty('url');

            if (!empty($url)) {
                $recordNode = $this->getByUrl($url, $node, true);
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
            // $node = 'we-need-a-node-here';
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);

            if (!empty($url) && count($subgraph->findDescendantNodes($node->aggregateId, \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter::create(nodeTypes: \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria::create(\Neos\ContentRepository\Core\NodeType\NodeTypeNames::fromStringArray(['BetterEmbed.NeosEmbed:Mixin.Item']), \Neos\ContentRepository\Core\NodeType\NodeTypeNames::createEmpty()), searchTerm: ['url' => str_replace('"', '', json_encode($url))]))) <= 1) {
                $recordNode = $this->getByUrl($url, $node);
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
    public function getByUrl(string $url, Node $contextNode, $createIfNotFound = false)
    {
        $root = $this->getBetterEmbedRootFor($contextNode);
        if ($root === null) {
            if (!$createIfNotFound) {
                return null;
            }
            $root = $this->nodeService->findOrCreateBetterEmbedRootNode($contextNode);
            // Note: update your NodeService::findOrCreateBetterEmbedRootNode() to use CR 9 commands and the context from $contextNode
        }

        /** @var \Neos\ContentRepository\Core\Projection\ContentGraph\Node $record */
        // $node = $this->nodeService->findRecordByUrl($this->context->getNode('/' . BetterEmbedRepository::BETTER_EMBED_ROOT_NODE_NAME), $url);
        $node = $this->nodeService->findRecordByUrl($root, $url);

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

        $contentRepository = $this->contentRepositoryRegistry->get(
            \Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString(
                BetterEmbedRepository::BETTER_EMBED_ROOT_NODE_NAME
            )
        );

        /** @var \Neos\ContentRepository\Core\NodeType\NodeType $nodeType */
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType('BetterEmbed.NeosEmbed:Record');

        $rootNode = $this->nodeService->findOrCreateBetterEmbedRootNode();

        $nodeCreateCommand = CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            NodeAggregateId::create(),
            $nodeType->name,
            OriginDimensionSpacePoint::fromDimensionSpacePoint(
                $dimensionSpacePoint = DimensionSpacePoint::fromArray([])
            ),
            $rootNode->aggregateId
        )->withInitialPropertyValues(
            PropertyValues::fromArray([
                'url' => $record->getUrl(),
                'itemType' => $record->getItemType(),
                'title' => $record->getTitle(),
                'body' => $record->getBody(),
                'thumbnailUrl' => $record->getThumbnailUrl(),
                'thumbnailContentType' => $record->getThumbnailContentType(),
                'thumbnailContent' => $record->getThumbnailContent(),
                'thumbnail' => $image, // keep if your node type allows Image/Asset values
                'embedHtml' => $record->getEmbedHtml(),
                'authorName' => $record->getAuthorName(),
                'authorUrl' => $record->getAuthorUrl(),
                'authorImage' => $record->getAuthorImage(),
                'publishedAt' => $record->getPublishedAt(),
                'uriPathSegment' => 'embed-' . random_int(0, 9999999999),
            ])
        )->withNodeName(NodeName::fromString(Algorithms::generateUUID()));

        $result = $contentRepository->handle($nodeCreateCommand);

        $created = $subgraph->findChildNodes(
            $rootNode->aggregateId,
            \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter::create(
                $create->nodeName
            )
        )[0] ?? null;

        return $created;
    }


}
