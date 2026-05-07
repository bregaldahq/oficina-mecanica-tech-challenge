<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new Config())
    ->setRules([
        '@PSR12'                       => true,
        'declare_strict_types'         => true,
        'array_syntax'                 => ['syntax' => 'short'],
        'ordered_imports'              => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'            => true,
        'trailing_comma_in_multiline'  => true,
        'phpdoc_align'                 => ['align' => 'vertical'],
        'binary_operator_spaces'       => ['default' => 'align_single_space_minimal'],
        'blank_line_after_namespace'   => true,
        'blank_line_after_opening_tag' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setUsingCache(true);
