<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Support;

use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Stand-in that can be registered as a singleton (TcaSchemaFactory itself is
 * not one) without running the real constructor.
 */
final class InertTcaSchemaFactory extends TcaSchemaFactory implements SingletonInterface
{
    public function __construct() {}
}
