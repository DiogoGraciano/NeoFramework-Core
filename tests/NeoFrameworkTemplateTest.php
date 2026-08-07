<?php

namespace Tests;

require_once __DIR__ . '/Helpers/ModifierFunctions.php';

use NeoFramework\Core\Template;
use PHPUnit\Framework\TestCase;

/**
 * Os casos de "compatibilidade" abaixo foram validados byte a byte contra a
 * implementacao anterior da engine antes da substituicao. Nao altere os valores
 * esperados sem verificar que a mudanca e intencional.
 */
class NeoFrameworkTemplateTest extends TestCase
{
    private string $dir;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \sys_get_temp_dir() . '/neotpl_' . \bin2hex(\random_bytes(6));
        $this->cache = $this->dir . '/cache';
        \mkdir($this->dir, 0777, true);
        \mkdir($this->cache, 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
        parent::tearDown();
    }

    /**
     * Remocao recursiva sem GLOB_BRACE: essa constante e uma extensao GNU e nao
     * existe em builds musl (a imagem do projeto e php:8.4-fpm-alpine), onde
     * referencia-la lanca "Undefined constant" independente da barra de namespace.
     */
    private static function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (\is_dir($path)) {
                self::removeTree($path);
            } else {
                @\unlink($path);
            }
        }

        @\rmdir($dir);
    }

    /** Escreve um template e devolve o caminho. */
    private function tpl(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        \file_put_contents($path, $content);

        return $path;
    }

    /** Instancia sempre com cache proprio e isolado do teste. */
    private function make(string $path, bool $accurate = false): Template
    {
        return new Template($path, $accurate, $this->cache);
    }

    /** Instancia com o cache desligado, para exercitar o caminho de compilacao. */
    private function makeUncached(string $path, bool $accurate = false): Template
    {
        return new Template($path, $accurate, '');
    }

    // ================================================== variaveis simples

    public function testSimpleVar()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $tpl->FOO = 'bar';
        $this->assertEquals('bar', \trim($tpl->parse()));
    }

    public function testVarNaoSetadaResolveParaVazio()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $this->assertEquals('', \trim($tpl->parse()));
    }

    public function testVarsSaoCaseSensitive()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $this->assertTrue($tpl->exists('FOO'));
        $this->assertFalse($tpl->exists('foo'));
    }

    public function testExistsReconheceBaseDeVariavelDeObjeto()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO->bar}\n"));
        $this->assertTrue($tpl->exists('FOO'));
    }

    public function testSetarVarInexistenteLancaExcecao()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $this->expectException(\RuntimeException::class);
        $tpl->NAO_EXISTE = 'x';
    }

    public function testGetDevolveValorSetado()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $tpl->FOO = 'bar';
        $this->assertEquals('bar', $tpl->FOO);
    }

    public function testGetDeVarNaoSetadaLancaExcecao()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $this->expectException(\RuntimeException::class);
        $tpl->FOO;
    }

    public function testArrayEConvertidoEmListaSeparadaPorVirgula()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $tpl->FOO = ['bar', 'baz', 'qux'];
        $this->assertEquals('bar, baz, qux', \trim($tpl->parse()));
    }

    public function testClearZeraOValorDaVariavel()
    {
        $tpl = $this->make($this->tpl('a.html', "[{FOO}]\n"));
        $tpl->FOO = 'bar';
        $tpl->clear('FOO');
        $this->assertEquals('[]', \trim($tpl->parse()));
    }

    public function testVariavelRepetidaEhSubstituidaEmTodasAsOcorrencias()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}-{FOO}-{FOO}\n"));
        $tpl->FOO = 'x';
        $this->assertEquals('x-x-x', \trim($tpl->parse()));
    }

    public function testEscapeDeVariavel()
    {
        $tpl = $this->make($this->tpl('a.html', "This is an escaped var:\n{{_}FOO}\n"));
        $this->assertEquals("This is an escaped var:\n{FOO}", \trim($tpl->parse()));
    }

    public function testComentariosSaoRemovidos()
    {
        $tpl = $this->make($this->tpl('a.html', "<!--- oculto --->antes {FOO} depois\n"));
        $tpl->FOO = 'x';
        $this->assertEquals('antes x depois', \trim($tpl->parse()));
    }

    // ============================================================= blocos

    public function testBlocoNaoParseadoNaoAparece()
    {
        $tpl = $this->make($this->tpl('a.html', "<!-- BEGIN B -->\nText with VAR: {FOO}\n<!-- END B -->\n"));
        $tpl->FOO = 'bar';
        $this->assertEquals('', \trim($tpl->parse()));
    }

    public function testBlocoSimplesAcumula()
    {
        $tpl = $this->make($this->tpl('a.html', "<!-- BEGIN B -->\nText with VAR: {FOO}\n<!-- END B -->\n"));
        $tpl->FOO = 'bar';
        $tpl->block('B');
        $tpl->FOO = 'baz';
        $tpl->block('B');
        $this->assertEquals("Text with VAR: bar\nText with VAR: baz", \trim($tpl->parse()));
    }

    public function testBlocosAninhados()
    {
        $tpl = $this->make($this->tpl('a.html',
            "<!-- BEGIN OUT -->\nOut top content with {OUT_TOP_VAR}\n"
            . "<!-- BEGIN INNER -->\nInner content with {INNER_VAR}-\n<!-- END INNER -->\n"
            . "Out bottom content with {OUT_BOTTOM_VAR}\n<!-- END OUT -->\n"));

        $tpl->OUT_TOP_VAR = 'foo';
        $tpl->OUT_BOTTOM_VAR = 'bar';
        $tpl->INNER_VAR = 'baz';
        $tpl->block('INNER');
        $tpl->INNER_VAR = 'qux';
        $tpl->block('INNER');

        $this->assertEquals(
            "Out top content with foo\nInner content with baz-\nInner content with qux-\nOut bottom content with bar",
            \trim($tpl->parse())
        );
    }

    public function testBlocosAninhadosComComentarios()
    {
        $tpl = $this->make($this->tpl('a.html',
            "<!--- um --->\n<!-- BEGIN OUT -->\nOut top content with {OUT_TOP_VAR}\n"
            . "<!--- dois --->\n<!-- BEGIN INNER -->\nInner content with {INNER_VAR}-\n<!-- END INNER -->\n"
            . "Out bottom content with {OUT_BOTTOM_VAR}\n<!-- END OUT -->\n"));

        $tpl->OUT_TOP_VAR = 'foo';
        $tpl->OUT_BOTTOM_VAR = 'bar';
        $tpl->INNER_VAR = 'baz';
        $tpl->block('INNER');
        $tpl->INNER_VAR = 'qux';
        $tpl->block('INNER');

        // A linha do comentario removido deixa uma linha em branco no lugar. Este
        // whitespace e o mesmo que a engine anterior produzia.
        $this->assertEquals(
            "Out top content with foo\n\nInner content with baz-\nInner content with qux-\nOut bottom content with bar",
            \trim($tpl->parse())
        );
    }

    public function testBlocoFilhoEhLimpadoAcadaIteracaoDoPai()
    {
        $tpl = $this->make($this->tpl('a.html',
            "<!-- BEGIN OUT -->[{O}:<!-- BEGIN INNER -->{I}<!-- END INNER -->]<!-- END OUT -->\n"));

        foreach (['a' => ['1', '2'], 'b' => ['3']] as $o => $inners) {
            foreach ($inners as $i) {
                $tpl->I = $i;
                $tpl->block('INNER');
            }
            $tpl->O = $o;
            $tpl->block('OUT');
        }

        $this->assertEquals('[a:12][b:3]', \trim($tpl->parse()));
    }

    public function testClearDeVarDentroDeBloco()
    {
        $tpl = $this->make($this->tpl('a.html',
            "<!-- BEGIN OPT -->\nTest if {VALUE} is {SELECTED}\n<!-- END OPT -->\n"));

        $tpl->VALUE = 'foo';
        $tpl->SELECTED = 'selected';
        $tpl->block('OPT');
        $tpl->clear('SELECTED');
        $tpl->block('OPT');

        $this->assertEquals("Test if foo is selected\nTest if foo is", \trim($tpl->parse()));
    }

    public function testBlocoInexistenteLancaExcecao()
    {
        $tpl = $this->make($this->tpl('a.html', "<!-- BEGIN B -->x<!-- END B -->\n"));
        $this->expectException(\InvalidArgumentException::class);
        $tpl->block('NAO_EXISTE');
    }

    public function testBlocoDuplicadoLancaExcecao()
    {
        $path = $this->tpl('a.html', "<!-- BEGIN B -->x<!-- END B --><!-- BEGIN B -->y<!-- END B -->\n");
        $this->expectException(\UnexpectedValueException::class);
        $this->make($path);
    }

    public function testBlocoMalFormadoLancaExcecao()
    {
        $path = $this->tpl('a.html', "<!-- BEGIN B -->sem fechamento\n");
        $this->expectException(\UnexpectedValueException::class);
        $this->make($path);
    }

    public function testSetParentAssociaBlocoManualmente()
    {
        $tpl = $this->make($this->tpl('a.html',
            "<!-- BEGIN A -->a<!-- END A -->\n<!-- BEGIN B -->b<!-- END B -->\n"));

        $tpl->setParent('A', 'B');
        $tpl->block('B');
        $tpl->block('A');

        // B foi limpado por ser filho declarado de A, entao so "a" sobra.
        $this->assertEquals('a', \trim($tpl->parse()));
    }

    // ============================================================ FINALLY

    public function testFinallyNaoAparaceQuandoOBlocoEhUsado()
    {
        $tpl = $this->make($this->tpl('a.html',
            "<!-- BEGIN B -->\nText with VAR: {FOO}\n<!-- END B -->\nContent in finally block\n<!-- FINALLY B -->\n"));

        $tpl->FOO = 'bar';
        $tpl->block('B');
        $this->assertEquals('Text with VAR: bar', \trim($tpl->parse()));
    }

    public function testFinallyApareceQuandoOBlocoNaoEhUsado()
    {
        $tpl = $this->make($this->tpl('a.html',
            "<!-- BEGIN B -->\nText with VAR: {FOO}\n<!-- END B -->\nContent in finally block\n<!-- FINALLY B -->\n"));

        $this->assertEquals('Content in finally block', \trim($tpl->parse()));
    }

    // ============================================================ objetos

    public function testObjetoComPropriedadePublica()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO->bar}\n"));
        $obj = new \stdClass();
        $obj->bar = 'foobar';
        $tpl->FOO = $obj;
        $this->assertEquals('foobar', \trim($tpl->parse()));
    }

    public function testObjetoComGetter()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO->bar}\n"));
        $tpl->FOO = new class {
            private $bar = 'foobar';

            public function getBar()
            {
                return $this->bar;
            }
        };
        $this->assertEquals('foobar', \trim($tpl->parse()));
    }

    public function testObjetoComMagicGet()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO->bar}\n"));
        $tpl->FOO = new class {
            private $bar = 'foobar';

            public function __get($name)
            {
                return $this->bar;
            }
        };
        $this->assertEquals('foobar', \trim($tpl->parse()));
    }

    public function testObjetoComToStringUsaOValorDaConversao()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $tpl->FOO = new class {
            public $bar = 'foobar';

            public function __toString(): string
            {
                return $this->bar;
            }
        };
        $this->assertEquals('foobar', \trim($tpl->parse()));
    }

    public function testObjetoSemToStringCaiParaJson()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $tpl->FOO = new class {
            public $bar = 'foobar';
        };
        $this->assertEquals('Object: {"bar":"foobar"}', \trim($tpl->parse()));
    }

    public function testCadeiaDePropriedades()
    {
        $tpl = $this->make($this->tpl('a.html', "{A->b->c}\n"));
        $inner = new \stdClass();
        $inner->c = 'fundo';
        $outer = new \stdClass();
        $outer->b = $inner;
        $tpl->A = $outer;
        $this->assertEquals('fundo', \trim($tpl->parse()));
    }

    public function testPropriedadeSemAcessorLancaExcecao()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO->inexistente}\n"));
        $tpl->FOO = new \stdClass();
        $this->expectException(\BadMethodCallException::class);
        $tpl->parse();
    }

    public function testObjetoEmVarSemPropriedadeNaoQuebra()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO->bar}\n"));
        $this->assertEquals('', \trim($tpl->parse()));
    }

    // ========================================================== modifiers

    public function testModifierSemArgumentos()
    {
        $tpl = $this->make($this->tpl('a.html', "{foo|upper}\n"));
        $tpl->foo = 'bar';
        $this->assertEquals('BAR', \trim($tpl->parse()));
    }

    public function testModifierComArgumentos()
    {
        $tpl = $this->make($this->tpl('a.html', "{foo|cut!3}\n"));
        $tpl->foo = 'abcdef';
        $this->assertEquals('abc', \trim($tpl->parse()));
    }

    public function testModifiersEncadeados()
    {
        $tpl = $this->make($this->tpl('a.html', "{foo|cut!3|upper}\n"));
        $tpl->foo = 'abcdef';
        $this->assertEquals('ABC', \trim($tpl->parse()));
    }

    public function testModifierEmPropriedadeDeObjeto()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO->bar|upper}\n"));
        $obj = new \stdClass();
        $obj->bar = 'foobar';
        $tpl->FOO = $obj;
        $this->assertEquals('FOOBAR', \trim($tpl->parse()));
    }

    public function testModifierInexistenteLancaExcecao()
    {
        $tpl = $this->make($this->tpl('a.html', "{foo|naoexiste}\n"));
        $tpl->foo = 'x';
        $this->expectException(\BadFunctionCallException::class);
        $tpl->parse();
    }

    /**
     * Regressao: a engine anterior nao deduplicava as expressoes de modifier, entao
     * cada ocorrencia repetida de {var|mod} acrescentava uma entrada nova e uma
     * substituicao redundante por linha renderizada.
     */
    public function testModifierRepetidoEhAplicadoUmaVezPorOcorrencia()
    {
        $body = \str_repeat('[{foo|upper}]', 20);
        $tpl = $this->make($this->tpl('a.html', "<!-- BEGIN B -->$body<!-- END B -->\n"));

        $tpl->foo = 'ab';
        $tpl->block('B');
        $tpl->foo = 'cd';
        $tpl->block('B');

        $this->assertEquals(
            \str_repeat('[AB]', 20) . \str_repeat('[CD]', 20),
            \trim($tpl->parse())
        );
    }

    // ================================================== multiplos arquivos

    public function testAddFile()
    {
        $this->tpl('child.html', "<p>{VAR}</p>\n");
        $tpl = $this->make($this->tpl('parent.html', "<div>{CONTENT}</div>\n"));
        $tpl->addFile('CONTENT', $this->dir . '/child.html');
        $tpl->VAR = 'foo';
        $this->assertEquals("<div><p>foo</p>\n</div>", \trim($tpl->parse()));
    }

    public function testAddFileComBlocoNoFilho()
    {
        $this->tpl('child.html', "<ul><!-- BEGIN LI --><li>{I}</li><!-- END LI --></ul>\n");
        $tpl = $this->make($this->tpl('parent.html', "<div>{CONTENT}</div>\n"));
        $tpl->addFile('CONTENT', $this->dir . '/child.html');

        $tpl->I = 'a';
        $tpl->block('LI');
        $tpl->I = 'b';
        $tpl->block('LI');

        $this->assertEquals("<div><ul><li>a</li><li>b</li></ul>\n</div>", \trim($tpl->parse()));
    }

    public function testAddFileEmVarInexistenteLancaExcecao()
    {
        $this->tpl('child.html', "x\n");
        $tpl = $this->make($this->tpl('parent.html', "<div>{CONTENT}</div>\n"));
        $this->expectException(\InvalidArgumentException::class);
        $tpl->addFile('NAO_EXISTE', $this->dir . '/child.html');
    }

    public function testBlocoDuplicadoEntreArquivosLancaExcecao()
    {
        $this->tpl('child.html', "<!-- BEGIN B -->y<!-- END B -->\n");
        $tpl = $this->make($this->tpl('parent.html', "<!-- BEGIN B -->x<!-- END B -->{CONTENT}\n"));
        $this->expectException(\UnexpectedValueException::class);
        $tpl->addFile('CONTENT', $this->dir . '/child.html');
    }

    public function testTemplatePhpEhExecutado()
    {
        $path = $this->dir . '/inc.php';
        \file_put_contents($path, '<?php echo "gerado por php"; ');
        $tpl = $this->make($path);
        $this->assertEquals('gerado por php', \trim($tpl->parse()));
    }

    // ============================================================= arquivo

    public function testArquivoInexistenteLancaExcecao()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->make($this->dir . '/nao_existe.html');
    }

    public function testArquivoVazioLancaExcecao()
    {
        $path = $this->tpl('vazio.html', '');
        $this->expectException(\InvalidArgumentException::class);
        $this->make($path);
    }

    // =============================================================== show

    public function testShowImprimeOConteudo()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $tpl->FOO = 'bar';

        \ob_start();
        $tpl->show();
        $out = \ob_get_clean();

        $this->assertEquals('bar', \trim($out));
    }

    // ====================================================== modo accurate

    /**
     * Valores esperados extraidos da engine anterior rodando o mesmo template.
     * A diferenca entre os dois modos e o \t que precedia o marcador END: o modo
     * accurate o consome, o modo normal o mantem antes do </pre>.
     */
    public function testModoAccurateEhEquivalenteAoComportamentoAnterior()
    {
        $path = $this->tpl('a.html', "<pre>\n\t<!-- BEGIN B -->\n\tlinha {V}\n\t<!-- END B -->\n</pre>\n");

        $render = function (bool $accurate) use ($path): string {
            $tpl = $this->make($path, $accurate);
            $tpl->V = '1';
            $tpl->block('B');
            $tpl->V = '2';
            $tpl->block('B');

            return $tpl->parse();
        };

        $this->assertEquals("<pre>\n\tlinha 1\n\tlinha 2\n</pre>\n", $render(true));
        $this->assertEquals("<pre>\n\tlinha 1\n\tlinha 2\n\t</pre>\n", $render(false));
    }

    public function testModoAccurateComTemplateSemTabs()
    {
        $path = $this->tpl('a.html', "inicio\n<!-- BEGIN B -->\nlinha {V}\n<!-- END B -->\nfim\n");

        $render = function (bool $accurate) use ($path): string {
            $tpl = $this->make($path, $accurate);
            $tpl->V = '1';
            $tpl->block('B');
            $tpl->V = '2';
            $tpl->block('B');

            return $tpl->parse();
        };

        $this->assertEquals("inicio\nlinha 1\nlinha 2\nfim\n", $render(true));
        $this->assertEquals("inicio\nlinha 1\nlinha 2\nfim\n", $render(false));
    }

    // ============================================================== cache

    public function testCacheProduzOMesmoResultadoQueACompilacao()
    {
        $path = $this->tpl('a.html',
            "<!-- BEGIN OUT -->[{O}:<!-- BEGIN INNER -->{I}<!-- END INNER -->]<!-- END OUT -->{TAIL}\n");

        $render = function (Template $tpl): string {
            $tpl->I = 'x';
            $tpl->block('INNER');
            $tpl->O = 'o';
            $tpl->block('OUT');
            $tpl->TAIL = '!';

            return $tpl->parse();
        };

        $semCache = $render($this->makeUncached($path));
        $frio = $render($this->make($path));       // compila e grava o cache
        $quente = $render($this->make($path));     // le do cache

        $this->assertEquals($semCache, $frio);
        $this->assertEquals($semCache, $quente);
        $this->assertEquals('[o:x]!', \trim($quente));
    }

    public function testCacheEhGravadoEmDisco()
    {
        $path = $this->tpl('a.html', "{FOO}\n");
        $this->assertCount(0, \glob($this->cache . '/*.php'));

        $this->make($path);

        $this->assertCount(1, \glob($this->cache . '/*.php'));
    }

    public function testCacheEhInvalidadoQuandoOArquivoMuda()
    {
        $path = $this->tpl('a.html', "versao um {FOO}\n");
        $tpl = $this->make($path);
        $tpl->FOO = 'x';
        $this->assertEquals('versao um x', \trim($tpl->parse()));

        // mtime precisa mudar para a chave de cache mudar
        \file_put_contents($path, "versao dois {FOO}\n");
        \touch($path, \time() + 10);
        \clearstatcache(true, $path);

        $tpl = $this->make($path);
        $tpl->FOO = 'y';
        $this->assertEquals('versao dois y', \trim($tpl->parse()));
    }

    public function testCacheDesligadoNaoGravaNada()
    {
        $path = $this->tpl('a.html', "{FOO}\n");
        $tpl = $this->makeUncached($path);
        $tpl->FOO = 'bar';

        $this->assertEquals('bar', \trim($tpl->parse()));
        $this->assertCount(0, \glob($this->cache . '/*.php'));
    }

    /**
     * Regressao: um cacheDir em branco precisa desligar o cache. Antes ele era
     * concatenado direto, virando '/<hash>.php' e gravando na raiz do filesystem.
     */
    public function testCacheDirEmBrancoNaoGravaNaRaizDoFilesystem()
    {
        $antes = \glob('/*.php') ?: [];

        foreach (['', '   '] as $vazio) {
            $tpl = new Template($this->tpl('a.html', "{FOO}\n"), false, $vazio);
            $tpl->FOO = 'bar';
            $this->assertEquals('bar', \trim($tpl->parse()));
        }

        $this->assertEquals($antes, \glob('/*.php') ?: []);
    }

    public function testCacheDirComBarraFinalFunciona()
    {
        $path = $this->tpl('a.html', "{FOO}\n");
        $tpl = new Template($path, false, $this->cache . '/');
        $tpl->FOO = 'bar';

        $this->assertEquals('bar', \trim($tpl->parse()));
        $this->assertCount(1, \glob($this->cache . '/*.php'));
    }

    public function testOMesmoArquivoUsadoComoRaizEComoAddFileCompartilhaCache()
    {
        $path = $this->tpl('frag.html', "<span>{V}</span>\n");
        $parent = $this->tpl('parent.html', "<div>{CONTENT}</div>\n");

        $raiz = $this->make($path);
        $raiz->V = 'a';
        $this->assertEquals('<span>a</span>', \trim($raiz->parse()));

        $tpl = $this->make($parent);
        $tpl->addFile('CONTENT', $path);
        $tpl->V = 'b';
        $this->assertEquals("<div><span>b</span>\n</div>", \trim($tpl->parse()));
    }

    // ============================================== regressoes corrigidas

    /**
     * Regressao: o ramo $append=false da engine anterior reatribuia o valor a si
     * mesmo, tornando a chamada um no-op.
     */
    public function testBlockComAppendFalseSubstituiOConteudo()
    {
        $tpl = $this->make($this->tpl('a.html', "<!-- BEGIN B -->[{V}]<!-- END B -->\n"));

        $tpl->V = 'a';
        $tpl->block('B');
        $tpl->V = 'b';
        $tpl->block('B', false);

        $this->assertEquals('[b]', \trim($tpl->parse()));
    }

    /**
     * Regressao: a engine anterior testava if($pointer), entao quantidade 0,
     * preco 0 e strings vazias renderizavam vazio em vez do valor real.
     */
    public function testPropriedadeComValorZeroEhRenderizada()
    {
        $tpl = $this->make($this->tpl('a.html', "qtd=[{P->qty}] preco=[{P->price}]\n"));
        $obj = new \stdClass();
        $obj->qty = 0;
        $obj->price = 0.0;
        $tpl->P = $obj;

        $this->assertEquals('qtd=[0] preco=[0]', \trim($tpl->parse()));
    }

    public function testPropriedadeNulaRenderizaVazio()
    {
        $tpl = $this->make($this->tpl('a.html', "[{P->nada}]\n"));
        $obj = new \stdClass();
        $obj->nada = null;
        $tpl->P = $obj;

        $this->assertEquals('[]', \trim($tpl->parse()));
    }

    /**
     * Regressao: a engine anterior varria a saida final com preg_replace para
     * remover placeholders orfaos, o que tambem apagava chaves de objeto de JS.
     * Hoje so os placeholders reconhecidos sao substituidos.
     */
    public function testChavesDeJavascriptQueNaoSaoVariaveisSaoPreservadas()
    {
        $tpl = $this->make($this->tpl('a.html', "<script>const o={foo:1}; fn({a:2});</script>{T}\n"));
        $tpl->T = 'ok';

        $this->assertEquals('<script>const o={foo:1}; fn({a:2});</script>ok', \trim($tpl->parse()));
    }

    /**
     * O valor de uma variavel nao e reinterpretado como template: um dado que
     * contenha {ALGO} sai intacto em vez de ser apagado.
     */
    public function testValorContendoChavesNaoEhReprocessado()
    {
        $tpl = $this->make($this->tpl('a.html', "{FOO}\n"));
        $tpl->FOO = 'valor com {CHAVE} dentro';

        $this->assertEquals('valor com {CHAVE} dentro', \trim($tpl->parse()));
    }

    // ================================================= carga representativa

    public function testTabelaGrandeRenderizaCorretamente()
    {
        $tpl = $this->make($this->tpl('a.html',
            "<h1>{TITULO}</h1>\n<table>\n<!-- BEGIN ROW -->"
            . "<tr><td>{ID}</td><td>{NOME}</td></tr>\n<!-- END ROW -->\n</table>\n"));

        $tpl->TITULO = 'Lista';
        for ($i = 1; $i <= 500; $i++) {
            $tpl->ID = (string) $i;
            $tpl->NOME = "nome $i";
            $tpl->block('ROW');
        }

        $out = $tpl->parse();

        $this->assertStringContainsString('<h1>Lista</h1>', $out);
        $this->assertEquals(500, \substr_count($out, '<tr>'));
        $this->assertStringContainsString('<td>1</td><td>nome 1</td>', $out);
        $this->assertStringContainsString('<td>500</td><td>nome 500</td>', $out);
        $this->assertStringNotContainsString('{', $out);
    }
}
