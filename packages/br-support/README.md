# NeoFramework BR Support

Helpers brasileiros opcionais e independentes do Core:

```bash
composer require diogodg/neoframework-br-support
```

```php
use NeoFramework\BrSupport\{Cep, Cnpj, Cpf, Dre};

Cpf::isValid('529.982.247-25');
Cnpj::format('11222333000181');
Cep::format('01001000');
Dre::isValid('1.2.3.10');
```

Validação de CPF e CNPJ verifica os dígitos verificadores. CEP e DRE validam a
estrutura; não consultam serviços externos nem afirmam que um endereço ou uma
classificação existe.
