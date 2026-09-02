# Validação

As regras são declaradas por atributos. A biblioteca que decide o booleano fica
atrás de `ValidatorInterface` e não aparece na assinatura de nada que a aplicação
escreva.

```php
use NeoFramework\Core\Attributes\{Email, Length, Rule};

final readonly class CreateUserRequest
{
    public function __construct(
        #[Email] public string $email,
        #[Length(min: 8)] public string $password,
        #[Rule('between', [18, 120])] public int $age,
        #[Rule('in', [['pix', 'boleto']], message: 'Forma de pagamento inválida.')]
        public string $payment,
    ) {}
}
```

`#[Email]` e `#[Length]` cobrem o comum. `#[Rule]` alcança o catálogo inteiro do
motor — `cpf`, `cnpj`, `date`, `url`, `ip`, `domain`, `creditCard`, `regex` e mais
de 150 outras — sem que o Core precise embrulhar cada uma num atributo próprio.
`#[Rule]` é repetível.

## Mensagens

Sem `message`, a mensagem vem do motor e cita o **nome do campo**, nunca o valor
recebido:

```
age must be between 18 and 120
```

Isso não é cosmético. A mensagem de validação é uma saída pública, e o campo pode
ser uma senha: o motor recebe o nome do campo explicitamente justamente para não
usar o valor como rótulo. Um campo marcado com `#[Sensitive]` segue a mesma
regra, e o valor recusado também não vai para o log.

Para uma mensagem própria, use `message:`. Ela substitui a do motor por inteiro.

## Escrevendo uma regra

Uma regra é um atributo que implementa `ValidationRule`:

```php
use NeoFramework\Core\Validation\{RuleSpec, ValidationRule};

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Slug implements ValidationRule
{
    public function spec(): RuleSpec
    {
        return new RuleSpec('regex', ['/^[a-z0-9-]+$/'], 'Deve ser um slug.');
    }
}
```

O binder a reconhece pelo contrato, não por uma lista de classes conhecidas —
uma regra de terceiro funciona sem alteração no Core.

## Regras de banco

`UniqueDb` e `ExistsDb` consultam pela NeoORM Query e estão no catálogo pelo
nome, em qualquer runtime:

```php
#[Rule('uniqueDb', [Tables::users(), Tables::users()->email])]
public string $email,
```

## Fora de um DTO

`Validator` valida arrays avulsos com o mesmo motor, então uma regra vale o mesmo
nos dois lugares:

```php
$result = (new Validator())->make(
    ['email' => $email, 'idade' => $idade],
    ['email' => 'email', 'idade' => new Rule('between', [18, 120])],
    ['email' => 'E-mail inválido.'],
);

if ($result->hasError()) {
    return $this->json(['errors' => $result->getErrors()], 422);
}
```

Cada campo aceita uma regra, um nome de regra, ou uma lista de qualquer um dos
dois. `getErrors()` devolve `array<string, list<string>>` — a mesma forma de
`ValidationException::$errors` e do Problem Details.

## Trocando o motor

Implemente `ValidatorInterface` e registre em `Config/container.php`:

```php
return [NeoFramework\Core\Validation\ValidatorInterface::class => new MeuValidador()];
```

Os DTOs não mudam: eles declaram `RuleSpec`, e traduzir spec em decisão é
responsabilidade do motor.

## Metadata compilada

Fazer bind de um DTO exigia Reflection em toda requisição: para cada parâmetro,
uma varredura de atributos com uma instanciação por atributo — `#[Sensitive]`,
`#[ListOf]`, as regras e, dentro do `InputSources`, a origem. O memo por
processo resolvia isso num runtime persistente e não resolvia nada sob PHP-FPM,
onde cada requisição é um processo novo.

`DtoMetadata` reduz cada classe a um plano — nome, tipo, origem já resolvida,
default, sensibilidade, tipo de elemento e regras instanciadas — e o bind passou
a consumir só o plano.

```bash
php neof dto:cache    # compila os DTOs alcançáveis pelas rotas
php neof dto:clear
```

A descoberta parte do mapa de rotas, seguindo DTOs aninhados e tipos de
`#[ListOf]`: um DTO que nenhuma action recebe não é carregado em requisição
nenhuma. As regras são gravadas como classe mais argumentos, não como objetos —
`var_export` de instância arbitrária exigiria `__set_state` em toda regra,
inclusive nas de terceiros; reinstanciar a partir dos argumentos não usa
Reflection.

Uma classe com default que `var_export` não reproduz (um objeto que não seja
enum) fica **de fora** do arquivo e continua passando por Reflection. Um cache
parcial é melhor do que um mapa que reconstrói o DTO com o default errado.
