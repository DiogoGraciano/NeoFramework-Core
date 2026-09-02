# Responses

`Response` cobre JSON, HTML, texto e redirect. O que carrega regra própria —
arquivo, cache, streaming — mora em factories separadas, para que um controller
que devolve JSON não pague por nada disso.

## Arquivos e downloads

```php
use NeoFramework\Core\Http\FileResponseFactory;

return FileResponseFactory::download($caminho, 'relatório.pdf', 'application/pdf', $request);
return FileResponseFactory::inline($caminho, mediaType: 'image/png', request: $request);
return FileResponseFactory::fromString($csv, 'export.csv', 'text/csv');
```

Passar o `$request` habilita `Range` e revalidação. Toda resposta de arquivo já
sai com `ETag`, `Last-Modified`, `Accept-Ranges` e `X-Content-Type-Options: nosniff`
— sem o último, o navegador pode ignorar o `Content-Type` declarado e adivinhar
pelo conteúdo, que é como um upload vira HTML executável.

`Content-Disposition` segue a RFC 6266 e emite as duas formas do nome, ASCII e
UTF-8, porque o header é ASCII e nomes de arquivo não são.

### Range

Um intervalo válido devolve `206` com `Content-Range` e um `Content-Length` que
descreve **o pedaço enviado**, não o arquivo. Um intervalo insatisfazível devolve
`416` com `bytes */tamanho`. Múltiplos intervalos exigiriam `multipart/byteranges`
e são atendidos como `200` com o arquivo inteiro — o que é legal e não mente sobre
o corpo.

`If-Range` é respeitado: retomar um download de um arquivo que mudou desde então
recebe o arquivo inteiro, não a continuação de outra versão.

## Cache condicional

```php
use NeoFramework\Core\Http\CacheControl;

return $this->json($dados)
    ->withEtag($versao)
    ->withCacheControl(CacheControl::private(60));
```

`CacheControl` tem construtores nomeados em vez de string livre: `noStore()`,
`private()`, `public()` e `mustRevalidate()`. É onde `private` vira `public` por
um typo — publicando no CDN uma resposta que pertencia a um usuário.

Registre `ConditionalRequestMiddleware` para transformar em `304` a resposta que
o cliente já tem. Ele só age sobre `200` de `GET`/`HEAD` que **já declaram** `ETag`
ou `Last-Modified`: gerar o validador ali exigiria ler o corpo inteiro, trocando
banda por memória sem o controller pedir.

Numa requisição condicional a comparação de `ETag` é fraca — `W/"x"` e `"x"`
designam a mesma representação — e `If-None-Match` vence `If-Modified-Since`,
porque o timestamp tem resolução de um segundo e erra quando o recurso muda duas
vezes no mesmo segundo.

## Streaming

```php
use NeoFramework\Core\Http\StreamedResponseFactory;

return StreamedResponseFactory::json($repositorio->cursor());

return StreamedResponseFactory::stream(static function (): Generator {
    foreach ($linhas as $linha) yield implode(',', $linha) . "\n";
}, 'text/csv');
```

O corpo é puxado bloco a bloco: a memória acompanha o bloco, não a resposta.
`ResponseEmitter` envia em pedaços de 8 KB e só descarrega o buffer quando o
corpo é de tamanho desconhecido — que é exatamente o caso em que isso importa.

## Server-Sent Events

```php
use NeoFramework\Core\Http\{ServerSentEvent, StreamedResponseFactory};

return StreamedResponseFactory::eventStream(static function (): Generator {
    foreach ($fila->consumir() as $mensagem) {
        yield new ServerSentEvent(json_encode($mensagem), event: 'mensagem', id: $mensagem->id);
    }
});
```

A resposta já sai com `X-Accel-Buffering: no`, sem o qual o nginx segura os
eventos e entrega tudo junto no fim — o oposto do ponto de SSE. Quebras de linha
em `id` e `event` são recusadas na construção: elas injetariam campos no fluxo, e
o cliente leria um evento que ninguém emitiu.

## Cookies

```php
use NeoFramework\Core\Http\Cookie;

return $response->withCookieObject(new Cookie('sessao', $token, secure: true, sameSite: 'Strict'));
```

`withCookie()` continua aceitando os argumentos soltos e constrói o mesmo valor.
`SameSite=None` sem `Secure` é recusado na construção: o navegador descartaria o
cookie em silêncio, e o sintoma seria uma sessão que só some em produção.
