<?php
declare(strict_types=1);

/**
 * Regras de estilo do Core.
 *
 * O conjunto é deliberadamente estreito. Este código usa `if (...) return x;` numa
 * linha em toda parte, e um preset como @PSR12 reescreveria milhares de linhas para
 * impor chaves — trocando um estilo consistente por outro e enterrando qualquer
 * diff de verdade no processo. O que está aqui pega o que é objetivamente errado
 * (import morto, espaço no fim da linha, `array()`), não o que é preferência.
 */
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // Higiene: nada aqui muda semântica nem reformata blocos.
        // O projeto escreve `<?php` e o `declare` na linha seguinte, sem linha em
        // branco entre eles. Sem estas duas regras o fixer junta os dois numa linha.
        'blank_line_after_opening_tag' => false,
        'linebreak_after_opening_tag' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'no_leading_import_slash' => true,
        'single_import_per_statement' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof' => true,
        'no_extra_blank_lines' => ['tokens' => ['extra', 'use']],
        'line_ending' => true,
        'encoding' => true,
        'full_opening_tag' => true,

        // Sintaxe moderna, sem tocar em formatação de bloco.
        'array_syntax' => ['syntax' => 'short'],
        'list_syntax' => ['syntax' => 'short'],
        'no_short_bool_cast' => true,
        'short_scalar_cast' => true,
        'lowercase_keywords' => true,
        'lowercase_static_reference' => true,
        'constant_case' => ['case' => 'lower'],
        'magic_constant_casing' => true,
        'native_function_casing' => true,
        'class_reference_name_casing' => true,

        // Tipagem: o `declare` é premissa do projeto e não pode faltar em arquivo novo.
        'declare_strict_types' => true,
        'blank_line_after_namespace' => true,

        // Comparação: `==` entre tipos diferentes é a origem de bug silencioso.
        'is_null' => true,
        // O `is_null` acima gera `null === $x`; o resto do código escreve
        // `$x === null`. Sem isto o fixer introduz um estilo que ele mesmo não
        // aplica em lugar nenhum.
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
        'modernize_types_casting' => true,

        // Espaçamento pontual, sem reindentar corpo de função.
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'cast_spaces' => ['space' => 'single'],
        'function_typehint_space' => true,
        'no_spaces_after_function_name' => true,
        'spaces_inside_parentheses' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,
        'ternary_operator_spaces' => true,
        'unary_operator_spaces' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin', __DIR__ . '/packages'])
            ->name('*.php')
            ->name('neof')
            ->notPath('Jobs_Test')
            // Artefatos gerados pela aplicação-sonda: container compilado e mapa
            // de rotas não são código-fonte e não devem entrar no diff.
            ->exclude('runtime/Cache')
            ->exclude('runtime/Logs')
    );
