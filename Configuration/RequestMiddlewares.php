<?php
/**
 * Definitions for middlewares provided by EXT:jumpurl
 */
return [
    'frontend' => [
        'friends-of-typo3/jumpurl' => [
            'target' => FoT3\Jumpurl\Middleware\JumpUrlHandler::class,
            'after' => [
                'typo3/cms-frontend/prepare-tsfe-rendering'
            ],
        ],
    ],
];
