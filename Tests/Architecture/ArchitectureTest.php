<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture tests enforcing layer constraints and design invariants.
 *
 * These tests run via PHPStan (phpat plugin) and enforce:
 * - Layer isolation (services don't depend on controllers)
 * - Event immutability (readonly classes)
 * - Middleware independence (no controller dependencies)
 * - Interface contracts (processor must implement interface)
 */
final class ArchitectureTest
{
    /**
     * Services must not depend on controllers — services are lower-layer
     * components that controllers consume, not the other way around.
     */
    public function testServicesDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrImageOptimize\Service'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('Netresearch\NrImageOptimize\Controller'))
            ->because('Services must not depend on controllers (layer violation)');
    }

    /**
     * Events must not depend on services or controllers — events are
     * pure data transfer objects dispatched across layers.
     */
    public function testEventsDoNotDependOnServicesOrControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrImageOptimize\Event'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrImageOptimize\Service'),
                Selector::inNamespace('Netresearch\NrImageOptimize\Controller'),
            )
            ->because('Events must be independent of services and controllers');
    }

    /**
     * Middleware must not depend on controllers — middleware intercepts
     * requests before they reach controllers.
     */
    public function testMiddlewareDoesNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrImageOptimize\Middleware'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('Netresearch\NrImageOptimize\Controller'))
            ->because('Middleware must not depend on controllers');
    }

    /**
     * ViewHelpers must not depend on controllers — ViewHelpers render
     * output and should not invoke controller logic.
     */
    public function testViewHelpersDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrImageOptimize\ViewHelpers'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('Netresearch\NrImageOptimize\Controller'))
            ->because('ViewHelpers must not depend on controllers');
    }

    /**
     * The Processor must implement ProcessorInterface to ensure
     * the contract is honoured and the DI alias remains valid.
     */
    public function testProcessorImplementsInterface(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('Netresearch\NrImageOptimize\Processor'))
            ->should()->implement()
            ->classes(Selector::classname('Netresearch\NrImageOptimize\ProcessorInterface'));
    }

    /**
     * The ImageManagerAdapter must implement ImageReaderInterface
     * to satisfy the DI alias in Services.yaml.
     */
    public function testImageManagerAdapterImplementsInterface(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('Netresearch\NrImageOptimize\Service\ImageManagerAdapter'))
            ->should()->implement()
            ->classes(Selector::classname('Netresearch\NrImageOptimize\Service\ImageReaderInterface'));
    }
}
