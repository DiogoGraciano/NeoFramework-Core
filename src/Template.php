<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use NeoFramework\Core\Template\CompiledTemplateCache;
use NeoFramework\Core\Template\TemplateCompiler;
use NeoFramework\Core\Template\TemplateRenderer;
use NeoFramework\Core\Template\TemplateSyntax;
use NeoFramework\Core\Template\TokenKind;
use NeoFramework\Core\Template\TokenResolverInterface;

/**
 * Template engine.
 *
 * O HTML fica em arquivos externos, livres de PHP. Suporta blocos auto-detectados,
 * limpeza automatica de blocos filhos, variaveis de objeto ({obj->prop}) e modifiers
 * ({var|funcao!arg}).
 *
 * Desenho: COMPILACAO e RENDERIZACAO sao separadas.
 *
 *  - Compilacao (1x por arquivo, cacheada em disco): extrai os corpos dos blocos e,
 *    para cada corpo, a lista MINIMA de placeholders que ocorrem nele.
 *  - Renderizacao (N vezes por request): cada corpo e resolvido com um unico strtr()
 *    usando um mapa do tamanho exato daquele corpo.
 *
 * A versao anterior fazia str_replace() com o mapa GLOBAL de valores a cada
 * substituicao, ou seja, uma passada por valor existente no template inteiro, mesmo
 * para renderizar uma linha de tabela de 90 bytes. O custo crescia com o tamanho do
 * template, nao com o do alvo.
 *
 * @author Diogo Graciano
 */
final class Template implements TokenResolverInterface
{
    /** Namespace onde os modifiers sao resolvidos. */
    private const MODIFIER_NAMESPACE = 'app\helpers\Functions::';

    // ----------------------------------------------------- estado compilado

    /** @var array<string,string> corpo cru por bloco; '.' e o documento raiz */
    private array $body = [];

    /** @var array<string,array<string,array>> placeholder => descritor, por corpo */
    private array $keys = [];

    /** @var array<string,list<string>> bloco pai => blocos filhos */
    private array $children = [];

    /** @var array<string,bool> blocos que possuem um corpo FINALLY */
    private array $hasFinally = [];

    /** @var array<string,bool> variaveis declaradas no documento */
    private array $vars = [];

    /** @var array<string,bool> blocos declarados no documento */
    private array $blocks = [];

    /** @var array<string,bool> variaveis preenchidas por addFile() */
    private array $subFiles = [];

    // ------------------------------------------------------- estado runtime

    /** @var array<string,string> valores escalares setados pelo usuario */
    private array $values = [];

    /** @var array<string,string> conteudo acumulado de cada bloco */
    private array $blockValues = [];

    /** @var array<string,object> instancias setadas pelo usuario */
    private array $instances = [];

    /** @var array<string,bool> blocos ja renderizados */
    private array $parsed = [];

    private bool $accurate;

    private CompiledTemplateCache $cache;

    private TemplateCompiler $compiler;

    private TemplateRenderer $renderer;

    /**
     * Cria um novo template usando $filename como arquivo principal.
     *
     * Quando $accurate e true os blocos sao substituidos preservando os caracteres
     * de tabulacao e as quebras de linha originais, gerando um documento fiel ao
     * arquivo. Impacta a performance. Util para arquivos com <pre> ou <code>.
     *
     * @param string      $filename arquivo a carregar
     * @param bool        $accurate true para substituicao de bloco fiel ao original
     * @param string|null $cacheDir diretorio do cache compilado. null (padrao) resolve
     *                              o diretorio automaticamente; string vazia desliga o
     *                              cache; qualquer outro valor e usado como esta
     */
    public function __construct(string $filename, bool $accurate = false, ?string $cacheDir = null)
    {
        $this->accurate = $accurate;

        $this->cache = CompiledTemplateCache::fromDirectory($cacheDir);
        $this->compiler = new TemplateCompiler($accurate);
        $this->renderer = new TemplateRenderer();

        $this->load('.', $filename);
    }

    /**
     * Coloca o conteudo de $filename na variavel de template $varname.
     *
     * @throws \InvalidArgumentException se a variavel nao existir no documento
     */
    public function addFile(string $varname, string $filename): void
    {
        if (!isset($this->vars[$varname])) {
            throw new \InvalidArgumentException("addFile: var $varname does not exist");
        }

        $this->load($varname, $filename);
        $this->subFiles[$varname] = true;

        // Promove as referencias a qualquer sub-documento ja registrado, e nao apenas
        // ao atual: o arquivo recem-carregado pode conter {OUTRO} de um addFile anterior.
        foreach ($this->subFiles as $sub => $_) {
            $placeholder = '{' . $sub . '}';
            foreach ($this->keys as $name => $keys) {
                if (isset($keys[$placeholder]) && $keys[$placeholder][0] === TokenKind::VARIABLE) {
                    $this->keys[$name][$placeholder] = [TokenKind::FILE, $sub];
                }
            }
        }
    }

    /**
     * Nao use diretamente. Setter de variavel de template.
     *
     * @throws \RuntimeException se a variavel nao existir no documento
     */
    public function __set(string $varname, mixed $value): void
    {
        if (!isset($this->vars[$varname])) {
            throw new \RuntimeException("var $varname does not exist");
        }

        if (\is_object($value)) {
            $this->instances[$varname] = $value;
            $string = \method_exists($value, '__toString')
                ? (string) $value
                : 'Object: ' . \json_encode($value);
        } elseif (\is_array($value)) {
            $string = \implode(', ', $value);
        } else {
            $string = (string) $value;
        }

        $this->values[$varname] = $string;
    }

    /**
     * Nao use diretamente. Getter de variavel de template.
     *
     * @throws \RuntimeException se a variavel nao existir
     */
    public function __get(string $varname): mixed
    {
        if (isset($this->values[$varname])) {
            return $this->values[$varname];
        }
        if (isset($this->instances[$varname])) {
            return $this->instances[$varname];
        }

        throw new \RuntimeException("var $varname does not exist");
    }

    /**
     * Informa se uma variavel de template existe. Variaveis sao case-sensitive.
     */
    public function exists(string $varname): bool
    {
        return isset($this->vars[$varname]);
    }

    /**
     * Limpa o valor de uma variavel.
     */
    public function clear(string $varname): void
    {
        $this->values[$varname] = '';
    }

    /**
     * Associa manualmente um bloco filho a um bloco pai.
     */
    public function setParent(string $parent, string $block): void
    {
        $this->children[$parent][] = $block;
    }

    /**
     * Exibe um bloco. Um bloco que nunca recebe block() nao aparece no resultado.
     *
     * @param string $block  nome do bloco
     * @param bool   $append true acumula o conteudo; false substitui o acumulado
     *
     * @throws \InvalidArgumentException se o bloco nao existir
     */
    public function block(string $block, bool $append = true): void
    {
        if (!isset($this->blocks[$block])) {
            throw new \InvalidArgumentException("block $block does not exist");
        }

        // Resolve os FINALLY dos filhos que nunca foram renderizados.
        if (isset($this->children[$block])) {
            foreach ($this->children[$block] as $child) {
                if (isset($this->hasFinally[$child]) && !isset($this->parsed[$child])) {
                    $this->blockValues[$child] = $this->resolve(TemplateSyntax::FINALLY_KEY . $child);
                    $this->parsed[$block] = true;
                }
            }
        }

        $content = $this->resolve($block);

        $this->blockValues[$block] = $append
            ? ($this->blockValues[$block] ?? '') . $content
            : $content;

        $this->parsed[$block] = true;

        // Limpa os filhos para a proxima iteracao do pai.
        if (isset($this->children[$block])) {
            foreach ($this->children[$block] as $child) {
                $this->blockValues[$child] = '';
            }
        }
    }

    /**
     * Devolve o conteudo final do documento.
     */
    public function parse(): string
    {
        // Renderiza automaticamente os pais cujos filhos foram renderizados.
        foreach (\array_reverse($this->children) as $parent => $children) {
            if (!isset($this->blocks[$parent]) || isset($this->parsed[$parent])) {
                continue;
            }
            foreach ($children as $child) {
                if (isset($this->parsed[$child])) {
                    $this->blockValues[$parent] = $this->resolve($parent);
                    $this->parsed[$parent] = true;
                    break;
                }
            }
        }

        // Renderiza os FINALLY dos blocos que nunca foram usados.
        foreach ($this->hasFinally as $block => $_) {
            if (!isset($this->parsed[$block])) {
                $this->blockValues[$block] = $this->resolve(TemplateSyntax::FINALLY_KEY . $block);
            }
        }

        // Nao ha varredura final para remover placeholders orfaos: uma variavel nao
        // setada ja resolve para '' por construcao. A versao anterior rodava um
        // preg_replace sobre a pagina inteira, que tambem destruia chaves legitimas
        // de JS/CSS que por acaso parecessem uma variavel.
        return $this->resolve('.');
    }

    /**
     * Imprime o conteudo final.
     */
    public function show(): void
    {
        echo $this->parse();
    }

    // ------------------------------------------------------------- RENDER

    /**
     * Resolve um corpo: monta um mapa com exatamente os placeholders daquele corpo
     * e faz uma unica passada de substituicao.
     */
    private function resolve(string $name): string
    {
        return $this->renderer->render($this->body[$name], $this->keys[$name], $this);
    }

    /** @param array<mixed> $token */
    public function resolveToken(array $token): string
    {
        return match ($token[0]) {
            TokenKind::VARIABLE => $this->values[$token[1]] ?? '',
            TokenKind::BLOCK => $this->blockValues[$token[1]] ?? '',
            TokenKind::FILE => $this->resolve($token[1]),
            TokenKind::PROPERTY => $this->property($token[1], $token[2]),
            default => $this->modify($token),
        };
    }

    /**
     * Percorre uma cadeia de acessores ({obj->a->b}) e devolve o valor final.
     *
     * @param list<string> $path caminho ja separado em tempo de compilacao
     */
    private function property(string $varname, array $path): string
    {
        $pointer = $this->instances[$varname] ?? null;
        if ($pointer === null) {
            return '';
        }

        foreach ($path as $step) {
            if ($pointer === null) {
                break;
            }

            $normalized = \strtolower(\str_replace('_', '', $step));

            if (\method_exists($pointer, "get$normalized")) {
                $pointer = $pointer->{"get$normalized"}();
            } elseif (\method_exists($pointer, '__get')) {
                $pointer = $pointer->__get($step);
            } elseif (\property_exists($pointer, $normalized)) {
                $pointer = $pointer->$normalized;
            } elseif (\property_exists($pointer, $step)) {
                $pointer = $pointer->{$step};
            } else {
                $class = \is_object($pointer) ? \get_class($pointer) : \gettype($pointer);
                throw new \BadMethodCallException(
                    "no accessor method in class $class for $varname->$step"
                );
            }
        }

        // Testa contra null, nao contra falsy: a versao anterior usava if($pointer),
        // o que fazia quantidade 0, preco 0 e flags false renderizarem vazio.
        if ($pointer === null) {
            return '';
        }
        if (\is_resource($pointer)) {
            return (string) \stream_get_contents($pointer);
        }

        return (string) $pointer;
    }

    /**
     * Aplica a cadeia de modifiers de um placeholder.
     *
     * @param array $key [S_MOD, varname, path|null, list<array{string,list<string>}>]
     */
    private function modify(array $key): string
    {
        $value = $key[2] === null
            ? ($this->values[$key[1]] ?? '')
            : $this->property($key[1], $key[2]);

        foreach ($key[3] as [$function, $arguments]) {
            $callable = self::MODIFIER_NAMESPACE . $function;
            if (!\is_callable($callable)) {
                throw new \BadFunctionCallException("modifier $function is not callable");
            }
            $value = $callable($value, ...$arguments);
        }

        return (string) $value;
    }

    // ------------------------------------------------------------ COMPILE

    /**
     * Carrega um arquivo e associa seu conteudo a $varname.
     *
     * @throws \InvalidArgumentException se o arquivo nao existir ou estiver vazio
     */
    private function load(string $varname, string $filename): void
    {
        if (!\is_file($filename)) {
            throw new \InvalidArgumentException("file $filename does not exist");
        }

        // Arquivos PHP sao executados; o buffer de saida vira o valor da variavel.
        if (self::isPHP($filename)) {
            \ob_start();
            require $filename;
            $this->body[$varname] = (string) \ob_get_clean();
            $this->keys[$varname] = [];

            return;
        }

        $cached = $this->cache->get($filename, $this->accurate);
        if ($cached !== null) {
            $this->merge($varname, $cached);

            return;
        }

        $compiled = $this->compiler->compile($filename);
        $this->merge($varname, $compiled);

        $this->cache->put($filename, $this->accurate, $compiled);
    }

    /**
     * Incorpora o resultado de uma compilacao (nova ou vinda do cache).
     *
     * @throws \UnexpectedValueException se um bloco colidir com outro ja carregado
     */
    private function merge(string $varname, array $compiled): void
    {
        foreach ($compiled['blocks'] as $block => $_) {
            if (isset($this->blocks[$block])) {
                throw new \UnexpectedValueException("duplicated block: $block");
            }
        }

        // O documento raiz do arquivo foi compilado sob a chave '.'; remapeia para
        // a variavel que o recebe, o que permite cachear o arquivo uma unica vez
        // independente de qual variavel o carrega.
        $body = $compiled['body'];
        $keys = $compiled['keys'];
        $children = $compiled['children'];

        if ($varname !== '.') {
            $body[$varname] = $body['.'];
            $keys[$varname] = $keys['.'];
            unset($body['.'], $keys['.']);

            if (isset($children['.'])) {
                $children[$varname] = $children['.'];
                unset($children['.']);
            }
        }

        $this->body += $body;
        $this->keys += $keys;
        $this->vars += $compiled['vars'];
        $this->blocks += $compiled['blocks'];
        $this->hasFinally += $compiled['finally'];

        foreach ($children as $parent => $list) {
            foreach ($list as $child) {
                $this->children[$parent][] = $child;
            }
        }

        foreach ($compiled['blocks'] as $block => $_) {
            $this->blockValues[$block] ??= '';
        }
    }

    private static function isPHP(string $filename): bool
    {
        return \in_array(
            \strtolower(\pathinfo($filename, PATHINFO_EXTENSION)),
            ['php', 'php5', 'cgi'],
            true
        );
    }

}
