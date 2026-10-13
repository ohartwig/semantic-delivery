<?php

declare(strict_types=1);

defined('TYPO3') or exit;

TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_semanticdelivery_channels' => [
        'label' => 'LLL:EXT:semantic_delivery/Resources/Private/Language/locallang.xlf:pages.channels',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectCheckBox',
            'items' => [
                ['label' => 'Website', 'value' => 'website'],
                ['label' => 'AI Agent', 'value' => 'ai-agent'],
                ['label' => 'Voice', 'value' => 'voice'],
                ['label' => 'Social Media', 'value' => 'social-media'],
            ],
            'default' => '',
        ],
    ],
    'tx_semanticdelivery_distribution_enabled' => [
        'label' => 'LLL:EXT:semantic_delivery/Resources/Private/Language/locallang.xlf:pages.distribution_enabled',
        'config' => [
            'type' => 'check',
            'default' => 0,
        ],
    ],
    'tx_semanticdelivery_distribution_platforms' => [
        'label' => 'LLL:EXT:semantic_delivery/Resources/Private/Language/locallang.xlf:pages.distribution_platforms',
        'description' => 'LLL:EXT:semantic_delivery/Resources/Private/Language/locallang.xlf:pages.distribution_platforms.description',
        'displayCond' => 'FIELD:tx_semanticdelivery_distribution_enabled:REQ:true',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectCheckBox',
            'itemsProcFunc' => Koh\SemanticDelivery\Infrastructure\Tca\DistributionPlatformItemsProcFunc::class . '->getItems',
            'default' => '',
        ],
    ],
]);

TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    '--div--;LLL:EXT:semantic_delivery/Resources/Private/Language/locallang.xlf:pages.tab,tx_semanticdelivery_channels,tx_semanticdelivery_distribution_enabled,tx_semanticdelivery_distribution_platforms',
);
