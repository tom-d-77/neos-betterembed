<?php

namespace BetterEmbed\NeosEmbed;

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\ContentRepository\Domain\Model\Node;
use BetterEmbed\NeosEmbed\Service\EmbedService;

class Package extends BasePackage
{
    /**
     * @param Bootstrap $bootstrap The current bootstrap
     * @return void
     */
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        // TODO 9.0 migration: The signal "nodeUpdated" on "Node" has been removed. Please check https://docs.neos.io/api/upgrade-instructions/9/signals-and-slots for further information, how to replace a signal.


        $dispatcher->connect(\Neos\ContentRepository\Core\Projection\ContentGraph\Node::class, 'nodeUpdated', EmbedService::class, 'nodeUpdated', false);
        // TODO 9.0 migration: The signal "nodeRemoved" on "Node" has been removed. Please check https://docs.neos.io/api/upgrade-instructions/9/signals-and-slots for further information, how to replace a signal.

        $dispatcher->connect(\Neos\ContentRepository\Core\Projection\ContentGraph\Node::class, 'nodeRemoved', EmbedService::class, 'nodeRemoved', false);
    }
}
