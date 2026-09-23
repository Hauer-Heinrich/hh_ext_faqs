<?php

$EM_CONF['hh_ext_faqs'] = [
    'title' => 'FAQ',
    'description' => 'Accessible, SEO friendly FAQ extension with live frontend search and jpfaq migration wizards',
    'category' => 'plugin',
    'author' => 'Martin Hofmann, Christian Hackl',
    'author_email' => 'web@hauer-heinrich.de',
    'author_company' => 'www.hauer-heinrich.de',
    'state' => 'stable',
    'version' => '1.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'fluid_styled_content' => '13.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
