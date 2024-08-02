<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
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
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
        ],
        'native_constant_invocation' => true,
    ])
    ->setFinder(
        Finder::create()
            ->exclude('vendor')
            ->in(__DIR__)
    )
    ->setUsingCache(false);
