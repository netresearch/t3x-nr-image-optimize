<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    die('This script supports command line usage only. Please check your command.');
}

$header = <<<EOF
This file is part of the package netresearch/nr-image-optimize.

For the full copyright and license information, please read the
LICENSE file that was distributed with this source code.
EOF;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                          => true,
        '@PER-CS2.0'                      => true,
        '@Symfony'                        => true,
        '@PHP80Migration'                 => true,
        '@PHP81Migration'                 => true,
        '@PHP82Migration'                 => true,
        '@PHP83Migration'                 => true,

        // Additional custom rules
        'declare_strict_types'            => true,
        'concat_space'                    => [
            'spacing' => 'one',
        ],
        'header_comment'                  => [
            'header'       => $header,
            'comment_type' => 'PHPDoc',
            'location'     => 'after_open',
            'separate'     => 'both',
        ],
        'phpdoc_to_comment'               => false,
        'phpdoc_no_alias_tag'             => false,
        'no_superfluous_phpdoc_tags'      => false,
        'phpdoc_separation'               => [
            'groups' => [
                [
                    'author',
                    'license',
                    'link',
                ],
            ],
        ],
        'no_alias_functions'              => true,
        'whitespace_after_comma_in_array' => [
            'ensure_single_space' => true,
        ],
        'single_line_throw'               => false,
        'self_accessor'                   => false,
        'global_namespace_import'         => [
            'import_classes'   => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'function_declaration'            => [
            'closure_function_spacing' => 'one',
            'closure_fn_spacing'       => 'one',
        ],
        'binary_operator_spaces'          => [
            'operators' => [
                '='  => 'align_single_space_minimal',
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'yoda_style'                      => [
            'equal'                => false,
            'identical'            => false,
            'less_and_greater'     => false,
            'always_move_variable' => false,
        ],
        // PHP 8.2+ compatibility rules
        'native_function_invocation'      => [
            'include' => ['@all'],
            'scope'   => 'all',
            'strict'  => true,
        ],
        'modernize_strpos'                => true,
        'get_class_to_class_keyword'      => true, // PHP 8.2+ feature
        'octal_notation'                  => true,  // Use 0o notation
        'modernize_types_casting'         => true,
        'no_unneeded_final_method'        => true,
        'nullable_type_declaration'       => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->exclude('.build')
            ->exclude('config')
            ->exclude('node_modules')
            ->exclude('var')
            ->notPath('ext_emconf.php')  // Exclude ext_emconf.php from checks
            ->in(__DIR__ . '/../')
    );
