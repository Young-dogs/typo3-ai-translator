<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Young Dogs AI Translate',
    'description' => 'AI-powered translation for TYPO3 backend using the Young Dogs translation service.',
    'version' => '1.0.0',
    'category' => 'backend',
    'state' => 'stable',
    'author' => 'Young-dogs',
    'author_email' => 'support@young-dogs.com',
    'author_company' => 'Young-dogs',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '14.3.0-14.3.99',
            'backend' => '14.3.0-14.3.99',
        ],
    ],
];
