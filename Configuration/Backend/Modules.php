<?php

use Koh\SemanticDelivery\Presentation\Controller\DistributionController;

return [
    'content_semanticdelivery' => [
        'parent' => 'content',
        'position' => ['after' => '*'],
        'access' => 'admin',
        'iconIdentifier' => 'semantic-delivery-module',
        'labels' => 'LLL:EXT:semantic_delivery/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => DistributionController::class . '::previewAction',
            ],
            'queue' => [
                'target' => DistributionController::class . '::queueAction',
            ],
            'log' => [
                'target' => DistributionController::class . '::logAction',
            ],
        ],
    ],
];
