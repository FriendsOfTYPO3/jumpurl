<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'JumpURL',
    'description' => 'Allows to modify links to create Jump URLs created in the frontend of the TYPO3 Core.',
    'category' => 'fe',
    'author' => 'Friends of TYPO3',
    'author_email' => 'friendsof@typo3.org',
    'state' => 'stable',
    'clearCacheOnLoad' => 1,
    'author_company' => '',
    'version' => '9.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
