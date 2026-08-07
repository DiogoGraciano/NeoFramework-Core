<?php

namespace NeoFramework\Core;

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
final class Template
{
    /** Tipos de placeholder resolvidos em tempo de render. */
    private const S_VAR = 0;
    private const S_BLOCK = 1;
    private const S_PROP = 2;
    private const S_MOD = 3;
    private const S_FILE = 4;

    /**
     * Prefixo do placeholder interno que substitui um bloco no corpo do pai.
     * Usa um prefixo improvavel em vez do sufixo "_value" da versao anterior, que
     * podia colidir com uma variavel real chamada {ALGO_value}.
     */
    private const MARKER = '__neoblk_';

    /** Chave interna dos corpos FINALLY. O \0 garante que nao colide com nome de bloco. */
    private const FINALLY_KEY = "\0finally\0";

    /** Incremente ao mudar o formato do cache compilado. */
    private const CACHE_VERSION = 2;

    /**
     * {VAR}, {VAR->prop->prop}, {VAR|mod!arg}
     * Classe de caracteres direta: a versao anterior usava ([[:alnum:]]|_)+ , uma
     * alternancia dentro de grupo capturante quantificado, e ((\|.*?)*)? , um
     * quantificador aninhado com risco de backtracking catastrofico.
     */
    private const RE_TOKEN = '/\{([A-Za-z0-9_]+)((?:->[A-Za-z0-9_]+)*)(\|[^}]*)?\}/';

    /** Marcadores de bloco. Uma passada sobre o documento inteiro. */
    private const RE_BLOCK = '/<!--\s*(BEGIN|END)\s+([A-Za-z0-9_]+)\s*-->/';

    /** Namespace onde os modifiers sao resolvidos. */
    private const MODIFIER_NAMESPACE = 'app\helpers\Functions::';

    /** Separador de argumentos de modifier: {var|funcao!arg1!arg2} */
    private const MODIFIER_ARG_SEPARATOR = '!';

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

    private ?string $cacheDir;

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

        if ($cacheDir === null) {
            $this->cacheDir = self::defaultCacheDir();
        } else {
            // Trim antes de comparar: um caminho em branco desliga o cache em vez de
            // virar '/<hash>.php' e escrever na raiz do filesystem.
            $this->cacheDir = \trim($cacheDir) === '' ? null : \rtrim($cacheDir, '/');
        }

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
                if (isset($keys[$placeholder]) && $keys[$placeholder][0] === self::S_VAR) {
                    $this->keys[$name][$placeholder] = [self::S_FILE, $sub];
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
                    $this->blockValues[$child] = $this->resolve(self::FINALLY_KEY . $child);
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
                $this->blockValues[$block] = $this->resolve(self::FINALLY_KEY . $block);
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
        $keys = $this->keys[$name];

        if ($keys === []) {
            return $this->body[$name];
        }

        $map = [];
        foreach ($keys as $placeholder => $key) {
            switch ($key[0]) {
                case self::S_VAR:
                    $map[$placeholder] = $this->values[$key[1]] ?? '';
                    break;
                case self::S_BLOCK:
                    $map[$placeholder] = $this->blockValues[$key[1]] ?? '';
                    break;
                case self::S_FILE:
                    $map[$placeholder] = $this->resolve($key[1]);
                    break;
                case self::S_PROP:
                    $map[$placeholder] = $this->property($key[1], $key[2]);
                    break;
                default:
                    $map[$placeholder] = $this->modify($key);
                    break;
            }
        }

        return \strtr($this->body[$name], $map);
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

        $cacheFile = $this->cacheFile($filename);

        if ($cacheFile !== null && \is_file($cacheFile)) {
            /** @var array{body:array,keys:array,children:array,finally:array,vars:array,blocks:array} $cached */
            $cached = require $cacheFile;
            $this->merge($varname, $cached);

            return;
        }

        $compiled = $this->compile($filename);
        $this->merge($varname, $compiled);

        if ($cacheFile !== null) {
            self::writeCache($cacheFile, $compiled);
        }
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

    /**
     * Compila um arquivo: extrai blocos e placeholders.
     *
     * @return array{body:array,keys:array,children:array,finally:array,vars:array,blocks:array}
     */
    private function compile(string $filename): array
    {
        $source = \preg_replace('/<!---.*?--->/s', '', (string) \file_get_contents($filename));

        if ($source === null || $source === '') {
            throw new \InvalidArgumentException("file $filename is empty");
        }

        $vars = [];
        if (\preg_match_all(self::RE_TOKEN, $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $vars[$match[1]] = true;
            }
        }

        // Monta a arvore de blocos em UMA passada de regex sobre o documento.
        // A versao anterior quebrava o arquivo em linhas e executava duas regex por
        // linha, sendo que o resultado da primeira era descartado.
        $tree = [];
        $stack = [];
        if (\preg_match_all(self::RE_BLOCK, $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($match[1] === 'BEGIN') {
                    $tree[$stack === [] ? '.' : \end($stack)][] = $match[2];
                    $stack[] = $match[2];
                } else {
                    \array_pop($stack);
                }
            }
        }

        $bodies = ['.' => $source];
        $blocks = [];
        $children = [];
        $finally = [];

        foreach ($tree as $parent => $list) {
            foreach ($list as $block) {
                if (isset($blocks[$block])) {
                    throw new \UnexpectedValueException("duplicated block: $block");
                }
                $blocks[$block] = true;
                $children[$parent][] = $block;

                [$body, $finallyBody, $bodies[$parent]] = $this->splitBlock($bodies[$parent], $block);
                $bodies[$block] = $body;

                if ($finallyBody !== null) {
                    $bodies[self::FINALLY_KEY . $block] = $finallyBody;
                    $finally[$block] = true;
                }
            }
        }

        $keys = [];
        foreach ($bodies as $name => $body) {
            $keys[$name] = $this->extractKeys($body);
        }

        return [
            'body' => $bodies,
            'keys' => $keys,
            'children' => $children,
            'finally' => $finally,
            'vars' => $vars,
            'blocks' => $blocks,
        ];
    }

    /**
     * Remove um bloco do corpo do pai, devolvendo [corpo, corpoFinally|null, paiAtualizado].
     *
     * Usa as mesmas expressoes da versao anterior, para que o whitespace dos corpos
     * extraidos seja identico byte a byte. Elas rodam apenas em tempo de compilacao
     * e o resultado vai para o cache, entao o custo e amortizado.
     *
     * @return array{0:string,1:string|null,2:string}
     *
     * @throws \UnexpectedValueException se o bloco estiver mal formado
     */
    private function splitBlock(string $parent, string $block): array
    {
        if ($this->accurate) {
            $parent = \str_replace("\r\n", "\n", $parent);
            $regex = "/\t*<!--\s*BEGIN\s+$block\s+-->\n*(\s*.*?\n?)\t*<!--\s+END\s+$block\s*-->\n*((\s*.*?\n?)\t*<!--\s+FINALLY\s+$block\s*-->\n?)?/sm";
        } else {
            $regex = "/<!--\s*BEGIN\s+$block\s+-->\s*(\s*.*?\s*)<!--\s+END\s+$block\s*-->\s*((\s*.*?\s*)<!--\s+FINALLY\s+$block\s*-->)?\s*/sm";
        }

        if (1 !== \preg_match($regex, $parent, $match, PREG_OFFSET_CAPTURE)) {
            throw new \UnexpectedValueException("mal-formed block $block");
        }

        // substr_replace com o offset da captura, em vez de uma segunda execucao de
        // preg_replace sobre a mesma string.
        $updated = \substr_replace(
            $parent,
            '{' . self::MARKER . $block . '}',
            $match[0][1],
            \strlen($match[0][0])
        );

        $finally = (isset($match[3]) && $match[3][1] !== -1) ? $match[3][0] : null;

        return [$match[1][0], $finally, $updated];
    }

    /**
     * Lista os placeholders distintos de um corpo.
     *
     * A chave do array e o proprio placeholder, portanto ocorrencias repetidas
     * colapsam. Isso elimina por construcao o acumulo de modifiers duplicados que
     * a versao anterior sofria: cada {var|mod} repetido gerava uma entrada nova e,
     * consequentemente, uma substituicao redundante por linha renderizada.
     *
     * @return array<string,array>
     */
    private function extractKeys(string $body): array
    {
        $keys = [];

        if (!\preg_match_all(self::RE_TOKEN, $body, $matches, PREG_SET_ORDER)) {
            return $keys;
        }

        foreach ($matches as $match) {
            $placeholder = $match[0];
            if (isset($keys[$placeholder])) {
                continue;
            }

            $name = $match[1];
            $properties = $match[2] ?? '';
            $modifiers = $match[3] ?? '';

            $path = $properties === '' ? null : \array_slice(\explode('->', $properties), 1);

            if ($modifiers === '') {
                if ($path !== null) {
                    $keys[$placeholder] = [self::S_PROP, $name, $path];
                } elseif (\str_starts_with($name, self::MARKER)) {
                    $keys[$placeholder] = [self::S_BLOCK, \substr($name, \strlen(self::MARKER))];
                } else {
                    // Sempre S_VAR aqui. A promocao para S_FILE acontece em addFile(),
                    // e nao durante a compilacao, para que o resultado compilado seja
                    // funcao apenas do conteudo do arquivo e possa ser cacheado com
                    // seguranca independente da ordem das chamadas de addFile().
                    $keys[$placeholder] = [self::S_VAR, $name];
                }

                continue;
            }

            // Cadeia de modifiers resolvida em tempo de compilacao; a versao anterior
            // fazia explode() a cada render.
            $chain = [];
            foreach (\explode('|', \ltrim($modifiers, '|')) as $statement) {
                if ($statement === '') {
                    continue;
                }
                $parts = \explode(self::MODIFIER_ARG_SEPARATOR, $statement);
                $function = \array_shift($parts);
                // O nome vem do arquivo de template, entao so aceita identificador PHP
                // simples. Somado ao namespace fixo, o alvo possivel fica restrito aos
                // metodos estaticos de uma unica classe.
                if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $function) !== 1) {
                    throw new \InvalidArgumentException("invalid modifier name: $function");
                }
                // array_slice, nao array_diff: a versao anterior comparava o nome cru
                // da funcao com o nome ja prefixado pelo namespace, entao nunca removia
                // nada e o proprio nome do modifier vazava como primeiro argumento.
                $chain[] = [$function, $parts];
            }

            $keys[$placeholder] = [self::S_MOD, $name, $path, $chain];
        }

        return $keys;
    }

    private static function isPHP(string $filename): bool
    {
        return \in_array(
            \strtolower(\pathinfo($filename, PATHINFO_EXTENSION)),
            ['php', 'php5', 'cgi'],
            true
        );
    }

    // -------------------------------------------------------------- CACHE

    /**
     * Diretorio de cache padrao. Devolve null quando o cache esta desligado ou o
     * diretorio nao e utilizavel; nesse caso o template e compilado a cada request.
     */
    private static function defaultCacheDir(): ?string
    {
        if (\function_exists('env') && \strtolower(env('TEMPLATE_CACHE', 'true')) === 'false') {
            return null;
        }

        $dir = Functions::getRoot() . 'Cache/templates';

        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return null;
        }

        return \is_writable($dir) ? $dir : null;
    }

    /**
     * Caminho do arquivo de cache compilado. A chave inclui o mtime do arquivo,
     * portanto editar o template invalida o cache automaticamente.
     */
    private function cacheFile(string $filename): ?string
    {
        if ($this->cacheDir === null) {
            return null;
        }

        $key = \md5(\implode('|', [
            self::CACHE_VERSION,
            \realpath($filename) ?: $filename,
            (string) \filemtime($filename),
            $this->accurate ? '1' : '0',
        ]));

        return $this->cacheDir . '/' . $key . '.php';
    }

    /**
     * Grava o cache de forma atomica: escreve em um temporario e renomeia, para que
     * dois processos concorrentes nunca leiam um arquivo pela metade.
     */
    private static function writeCache(string $cacheFile, array $compiled): void
    {
        $temp = $cacheFile . '.' . \getmypid() . '.tmp';

        if (@\file_put_contents($temp, '<?php return ' . \var_export($compiled, true) . ';') === false) {
            return;
        }

        if (!@\rename($temp, $cacheFile)) {
            @\unlink($temp);
        }
    }
}
