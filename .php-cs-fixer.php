<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@Symfony' => true,
        'concat_space' => [
            'spacing' => 'one',
        ],
        'yoda_style' => [
            'equal' => false,
            'identical' => false,
        ],
        'final_class' => true,
        'declare_strict_types' => true,
        'no_useless_else' => true,
        'not_operator_with_successor_space' => true,
        'not_operator_with_space' => false,
        'multiline_comment_opening_closing' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->exclude('vendor')
            ->in(__DIR__)
    )
    ->setUsingCache(false);
