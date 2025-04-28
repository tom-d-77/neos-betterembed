<?php

namespace BetterEmbed\NeosEmbed\Service;

use BetterEmbed\NeosEmbed\Domain\Repository\BetterEmbedRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeTemplate;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Eel\Exception;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\TagRepository;
use phpDocumentor\Reflection\Types\Boolean;

/**
 * @Flow\Scope("singleton")
 */
class NodeService
{

    const ASSET_COLLECTION_TITLE = 'BetterEmbed';

    /**
     * @Flow\Inject
     * @var BetterEmbedRepository
     */
    protected $betterEmbedRepository;

    /**
     * @var \Neos\ContentRepository\Core\Projection\ContentGraph\Node
     */
    protected $betterEmbedRootNode;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var AssetCollectionRepository
     */
    protected $assetCollectionRepository;

    /**
     * @Flow\Inject
     * @var TagRepository
     */
    protected $tagRepository;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @param \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context
     *
     * @return \Neos\ContentRepository\Core\Projection\ContentGraph\Node
     */
    public function findOrCreateBetterEmbedRootNode(\Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context)
    {

        if ($this->betterEmbedRootNode instanceof \Neos\ContentRepository\Core\Projection\ContentGraph\Node) {
            return $this->betterEmbedRootNode;
        }

        $betterEmbedRootNodeData = $this->betterEmbedRepository->findOneByPath('/' . BetterEmbedRepository::BETTER_EMBED_ROOT_NODE_NAME, $context->getWorkspace());

        if ($betterEmbedRootNodeData !== null) {
            $this->betterEmbedRootNode = $this->nodeFactory->createFromNodeData($betterEmbedRootNodeData, $context);

            return $this->betterEmbedRootNode;
        }
        // TODO 9.0 migration: !! NodeTemplate is removed in Neos 9.0. Use the "CreateNodeAggregateWithNode" command to create new nodes or "CreateNodeVariant" command to create variants of an existing node in other dimensions.


        $nodeTemplate = new NodeTemplate();
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.
        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        $nodeTemplate->setNodeType($contentRepository->getNodeTypeManager()->getNodeType('unstructured'));
        // TODO 9.0 migration: !! NodeTemplate::setName is removed in Neos 9.0. Use the "CreateNodeAggregateWithNode" command to create new nodes or "CreateNodeVariant" command to create variants of an existing node in other dimensions.

        $nodeTemplate->setName(BetterEmbedRepository::BETTER_EMBED_ROOT_NODE_NAME);
        // TODO 9.0 migration: !! MEGA DIRTY CODE! Ensure to rewrite this; by getting rid of LegacyContextStub.
        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        $workspace = $contentRepository->findWorkspaceByName(\Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName::fromString($context->workspaceName ?? 'live'));
        $rootNodeAggregate = $contentRepository->getContentGraph($workspace->workspaceName)->findRootNodeAggregateByType(\Neos\ContentRepository\Core\NodeType\NodeTypeName::fromString('Neos.Neos:Sites'));
        $subgraph = $contentRepository->getContentGraph($workspace->workspaceName)->getSubgraph(\Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint::fromLegacyDimensionArray($context->dimensions ?? []), $context->invisibleContentShown ? \Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints::withoutRestrictions() : \Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints::default());

        $rootNode = $subgraph->findNodeById($rootNodeAggregate->nodeAggregateId);

        $this->betterEmbedRootNode = $rootNode->createNodeFromTemplate($nodeTemplate);
        $this->betterEmbedRepository->persistEntities();

        return $this->betterEmbedRootNode;
    }

    /**
     * @param string $title
     * @return AssetCollection
     * @throws IllegalObjectTypeException
     */
    public function findOrCreateBetterEmbedAssetCollection(string $title = self::ASSET_COLLECTION_TITLE): AssetCollection
    {
        /** @var AssetCollection $assetCollection */
        $assetCollection = $this->assetCollectionRepository->findByTitle($title)->getFirst();

        if ($assetCollection === null) {
            $assetCollection = new AssetCollection($title);

            $this->assetCollectionRepository->add($assetCollection);
            $this->persistenceManager->allowObject($assetCollection);
        }

        return $assetCollection;
    }

    /**
     * @param string $label
     * @return Tag
     * @throws IllegalObjectTypeException
     */
    public function findOrCreateBetterEmbedTag(string $label, ArrayCollection $assetCollections): Tag
    {
        /** @var Boolean $doCreateTag */
        $doCreateTag = false;

        /** @var Tag $tag */
        $tag = $this->tagRepository->findByLabel($label)->getFirst();

        if ($tag === null) { // check if tag exists
            return $this->createTag($label, $assetCollections);
        }

        /** @var AssetCollection $collection */
        foreach ($tag->getAssetCollections() as $collection) { //check if tag has the accoring asset collection assigned
            if ($collection->getTitle() === self::ASSET_COLLECTION_TITLE) {
                return $tag;
            }
        }

        return $this->createTag($label, $assetCollections); // create tag anyway
    }

    /**
     * @param string $label
     * @param ArrayCollection $assetCollections
     * @return Tag
     * @throws IllegalObjectTypeException
     */
    private function createTag(string $label, ArrayCollection $assetCollections): Tag
    {
        $tag = new Tag($label);
        $tag->setAssetCollections($assetCollections);

        $this->tagRepository->add($tag);
        $this->persistenceManager->allowObject($tag);

        return $tag;
    }

    /**
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node
     * @param string $url
     * @return \Traversable
     * @throws Exception
     */
    public function findRecordByUrl(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node, string $url)
    {

        $fq = new FlowQuery([$node]);

        /** @var \Traversable $result */
        $result = $fq->find(sprintf('[instanceof BetterEmbed.NeosEmbed:Record][url="%s"]', $url))->get(0);

        return $result;
    }

    public function removeEmbedNode(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node)
    {
        // TODO 9.0 migration: !! Node::setRemoved() is not supported by the new CR. Use the "RemoveNodeAggregate" command to remove a node.

        $node->setRemoved(true);
        // TODO 9.0 migration: !! Node::isRemoved() - the new CR *never* returns removed nodes; so you can simplify your code and just assume removed == FALSE in all scenarios.

        if ($node->isRemoved()) {
            $this->nodeDataRepository->remove($node);
            return;
        }
    }
}
