# Pacotes oficiais

O repositório mantém os adapters e módulos opcionais junto do Core para que a
mesma suíte os execute antes de uma publicação. Cada diretório abaixo possui
`composer.json` próprio, sem dependência circular.

| Pacote | Responsabilidade | Dependência obrigatória |
|---|---|---|
| `diogodg/neoframework-roadrunner` | worker HTTP do RoadRunner e bridge de sessão | Core, RoadRunner, PSR-7 |
| `diogodg/neoframework-opentelemetry` | métricas e traces OTel | Core, API OTel |
| `diogodg/neoframework-image-gd` | resize, EXIF e reencode WebP | Core, `ext-gd`, `ext-exif` |
| `diogodg/neoframework-br-support` | CPF, CNPJ, CEP e DRE | PHP apenas |

O Core não requer nenhum desses pacotes. Nos testes deste monorepo eles entram
somente pelo autoload de desenvolvimento; aplicações instalam o adapter de que
precisam pelo Composer.
