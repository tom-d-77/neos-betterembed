<?php

namespace BetterEmbed\NeosEmbed\Service;

use BetterEmbed\NeosEmbed\Domain\Repository\BetterEmbedRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;


use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateRootWorkspace;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateCurrentlyDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\Eel\Exception;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\TagRepository;
use phpDocumentor\Reflection\Types\Boolean;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;
use Psr\Log\LoggerInterface;

#[Flow\Scope("singleton")]
class NodeService
{

    const ASSET_COLLECTION_TITLE = 'BetterEmbed';

    /**
     * @var PersistenceManagerInterface
     */
    #[Flow\Inject]
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

    #[Flow\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    /**
     * @return NodeAggregate|null
     */
    public function findOrCreateBetterEmbedRootNode()
    {
        return $this->securityContext->withoutAuthorizationChecks(function () {
            $contentRepository = $this->contentRepositoryRegistry->get(
                ContentRepositoryId::fromString(BetterEmbedRepository::BETTER_EMBED_ROOT_NODE_NAME)
            );

            if ($contentRepository->findWorkspaceByName(WorkspaceName::forLive()) === null) {
                $this->logger->info('No "live" Workspace for ' . BetterEmbedRepository::BETTER_EMBED_ROOT_NODE_NAME);
                $this->logger->info('Create "live" Workspace for ' . BetterEmbedRepository::BETTER_EMBED_ROOT_NODE_NAME);
                $contentRepository->handle(CreateRootWorkspace::create(
                    WorkspaceName::forLive(),
                    ContentStreamId::create()
                ));
            }

            $rootNodeAggregates = $contentRepository->getContentGraph(
                WorkspaceName::fromString('live'))->findRootNodeAggregates(FindRootNodeAggregatesFilter::create()
            );

            return $rootNodeAggregates->first();
        });
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

    public function removeEmbedNode(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node):void
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);

        try {
            $contentRepository->handle(RemoveNodeAggregate::create(
                WorkspaceName::forLive(),
                $node->aggregateId,
                $node->originDimensionSpacePoint->toDimensionSpacePoint(),
                NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS
            ));
        } catch (NodeAggregateCurrentlyDoesNotExist | NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint) {
            // already removed by another command further up the graph
        }
    }
}
