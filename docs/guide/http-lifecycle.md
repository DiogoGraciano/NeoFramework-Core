# Ciclo HTTP

Cada requisição percorre um ciclo explícito. O framework não depende de estado global mutável para saber onde ela está; o estado temporário fica em um `RequestScope` e é descartado no fim.

```mermaid
flowchart LR
    A[Servidor / front controller] --> B[PSR-7 Request]
    B --> C[Middleware global]
    C --> D[Router]
    D --> E[Middleware da rota]
    E --> F[Binding e validação]
    F --> G[Action do controller]
    G --> H[PSR-7 Response]
    H --> I[ResponseEmitter]
```

## Mensagens imutáveis

Request e Response seguem PSR-7. Operações com headers, cookies e status retornam uma cópia; guarde o retorno:

```php
$response = $response
    ->withStatus(201)
    ->withHeader('Location', '/products/42');
```

Essa regra é particularmente importante em middleware. Não altere a resposta recebida esperando que outra camada a veja modificada; substitua a variável pela nova instância.

## Middleware

Middleware global pertence à configuração; middleware de controller ou action usa o atributo `#[Middleware]`. Cada middleware implementa PSR-15:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AddRequestHeader implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Application', 'NeoFramework');
    }
}
```

Middleware pode encerrar o ciclo retornando uma resposta sem chamar o handler — rate limit, autenticação e CORS usam exatamente esse mecanismo.

## Erros e respostas

Uma exceção HTTP carrega o status adequado e é convertida pelo handler de erros. Erros de validação usam Problem Details quando o cliente aceita JSON. Controllers devem retornar uma resposta, texto, array ou valor normalizável; jamais imprimir conteúdo diretamente.

Leia [controllers e respostas](/guide/controllers) para a superfície do controller, [roteamento](/ROUTING) para o matcher e [respostas](/RESPONSES) para arquivos, cache, streaming e SSE.
