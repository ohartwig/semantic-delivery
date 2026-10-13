<?php

return [
    'frontend' => [
        'koh/semantic-delivery-channel-detection' => [
            'target' => Koh\SemanticDelivery\Presentation\Middleware\ChannelDetectionMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/authentication',
                'typo3/cms-frontend/site',
            ],
        ],
        'koh/semantic-delivery-markdown-negotiation' => [
            'target' => Koh\SemanticDelivery\Presentation\Middleware\MarkdownNegotiationMiddleware::class,
            'before' => [
                'typo3/cms-frontend/content-length-headers',
            ],
            'after' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];
