<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

// Ruleset: заявленный стандарт проекта PER-CS2.0 (оба пресета, включая risky)
// плюс явно перечисленные расширения сверх него. Ранее поверх PER-CS2x0 был
// включён весь @PhpCsFixer (+risky) — фактический стиль был шире заявленного.
return new Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,
        'declare_strict_types' => true,

        // Расширения сверх PER-CS2.0 (конвенции проекта, см. AGENTS.md):
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'ordered_class_elements' => true,
        'class_attributes_separation' => [
            'elements' => [
                'const' => 'only_if_meta',
                'property' => 'only_if_meta',
                'method' => 'one',
            ],
        ],
        'final_public_method_for_abstract_class' => true,
        'native_constant_invocation' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'yoda_style' => false,
        'php_unit_internal_class' => false,
    ])
    ->setFinder(
        Finder::create()
            ->in(__DIR__)
            ->exclude('vendor'),
    )
    ->setCacheFile(__DIR__ . '/.cache/php-cs-fixer.cache')
;
