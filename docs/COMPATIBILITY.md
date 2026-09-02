# Compatibilidade e depreciação

O NeoFramework segue [SemVer](https://semver.org). Este documento define o que
conta como quebra — sem isso, "sai na 3.0" é uma frase, não um compromisso.

## O que é público

É coberto pela garantia de compatibilidade tudo que uma aplicação pode usar sem
truque:

- classes, interfaces, enums, atributos e funções **não** marcados `@internal`;
- assinaturas de métodos `public` e `protected` neles;
- nomes e formas das chaves de configuração em `Config/*.php`;
- nomes de comandos da CLI, suas opções e seus exit codes;
- o formato JSON de `--json`, de Problem Details e dos logs estruturados;
- nomes de eventos e o payload deles;
- headers HTTP que o framework emite por conta própria.

**Não** é público, e pode mudar em qualquer versão:

- qualquer coisa marcada `@internal`;
- membros `private`, e `protected` de classe `final`;
- o formato dos arquivos em `Cache/` — são artefatos gerados, não contrato;
- mensagens de exceção e de log (o *tipo* da exceção é público; o texto não);
- ordem de execução de middlewares que a aplicação não declarou;
- o número da linha e o texto de qualquer coisa que a CLI imprime para humanos.

## O que quebra o quê

### Patch (2.4.0 → 2.4.1)

Correção de bug sem mudança de assinatura. Corrigir um comportamento que
contradizia a documentação é patch, mesmo que alguém dependesse do bug.

### Minor (2.4.x → 2.5.0)

- classe, método, parâmetro opcional ou chave de configuração **novos**;
- depreciação de algo existente, que continua funcionando;
- novo `@internal`;
- constraint de dependência ampliado.

Adicionar método a uma interface é **major**, não minor: toda implementação de
terceiro para de compilar. Quando a adição é necessária antes da major, a saída é
uma interface nova que estende a antiga.

### Major (2.x → 3.0)

- remover ou renomear qualquer coisa pública;
- adicionar parâmetro obrigatório, ou estreitar o tipo de um existente;
- alargar o tipo de retorno, ou mudá-lo;
- tornar `final` uma classe que não era;
- mudar o default de uma chave de configuração de um jeito que altera
  comportamento observável;
- subir a versão mínima de PHP ou o major de uma dependência exposta na API
  pública.

## Ciclo de depreciação

Nada some sem aviso na versão anterior. Uma API depreciada:

1. ganha `@deprecated` no docblock, dizendo **o que usar no lugar** — nunca só
   "deprecated";
2. ganha uma seção no `UPGRADE.md` com a tradução mecânica de antes → depois;
3. continua funcionando, com o mesmo comportamento, por **toda a minor line**;
4. só então é removida, na próxima major.

Depreciação sem substituto pronto não é depreciação — é aviso de que algo vai
quebrar. Não marque `@deprecated` antes de o caminho novo existir e estar
documentado.

### Quando o ciclo não se aplica

Uma correção de **segurança** pode quebrar compatibilidade em minor ou patch. É a
única exceção, ela é deliberada, e vem com seção própria no `UPGRADE.md`
explicando o vetor. Um exemplo real da 2.x: o store de cache do rate limit
passava chaves com `:` para o PSR-6 e derrubava toda rota limitada — corrigir isso
mudou a chave gravada, e contadores em voo foram zerados.

## Como isso é verificado

- `composer test:all` roda estilo, análise estática e a suíte;
- o CI executa a matriz `lowest`/`highest` de dependências, então um constraint
  que virou mentira reprova antes de chegar em quem instala;
- toda quebra tem seção no `UPGRADE.md`, e o número dela é citado no changelog;
- o baseline do PHPStan só encolhe: erro novo não entra por baseline.

## Versões de PHP

A versão mínima suportada só sobe em major. A máxima acompanha os releases do PHP
assim que a suíte passa nela — isso é minor, porque só amplia.
