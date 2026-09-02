# Controllers e respostas

Um controller agrupa endpoints relacionados. A action declara o método, o caminho e os dados que recebe; o framework resolve o resto pelo contrato e pelos atributos.

## Endpoint JSON

```php
use App\Requests\CreateProduct;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Response;

final class ProductController extends Controller
{
    #[Route('/products', ['POST'], name: 'products.store')]
    public function store(CreateProduct $input): Response
    {
        $product = $this->products->create($input);

        return $this->json($product, 201)
            ->withHeader('Location', route('products.show', ['id' => $product->id]));
    }
}
```

O tipo `CreateProduct` dispara binding e validação antes de a action executar. Uma entrada inválida não chega parcialmente ao código de negócio.

## Dados da requisição

Os atributos deixam a origem explícita quando não é um DTO:

```php
use NeoFramework\Core\Attributes\{FromHeader, FromQuery, FromRoute, Route};

#[Route('/products/{id:\d+}', ['GET'])]
public function show(
    #[FromRoute] int $id,
    #[FromQuery('include')] ?string $include,
    #[FromHeader('X-Request-Id')] ?string $requestId,
): array {
    return ['id' => $id, 'include' => $include, 'requestId' => $requestId];
}
```

Use `#[FromBody]` quando um parâmetro individual vier do payload. Para formulários e payloads reais, prefira DTOs: eles concentram tipo, regra e mensagem ao lado do campo.

## Tipos de resposta

| Intenção | Escolha |
|---|---|
| JSON de API | `$this->json($data, 200)` |
| Texto simples | `$this->text('ok')` |
| HTML renderizado | layout/template da aplicação |
| Redirecionar | `$this->redirect('/login')` |
| Download, arquivo ou stream | `FileResponseFactory` / `StreamedResponseFactory` |

Para detalhes de ETag, Range, cache condicional, CSV em streaming e Server-Sent Events, consulte [respostas](/RESPONSES).

## URL por nome

Nomeie rotas que serão referenciadas. Isso elimina URLs duplicadas e ainda verifica constraints antes de devolver um link:

```php
route('products.show', ['id' => 42]);
route_url('products.show', ['id' => 42]); // exige app.url
```

O guia de [roteamento](/ROUTING) cobre prefixos, hosts, URLs assinadas, fallbacks, `OPTIONS` automático e cache de rotas.
