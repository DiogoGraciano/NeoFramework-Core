---
layout: home

hero:
  name: NeoFramework
  text: PHP previsível, do request ao deploy.
  tagline: Um framework HTTP para PHP 8.4 construído sobre PSR-7, PSR-11, PSR-14, PSR-15 e PSR-17. Tipado onde importa, explícito onde protege.
  actions:
    - theme: brand
      text: Começar agora
      link: /guide/getting-started
    - theme: alt
      text: Ver no GitHub
      link: https://github.com/DiogoGraciano/NeoFramework-Core

features:
  - icon: ⟶
    title: HTTP sem surpresa
    details: Rotas por atributos, mensagens PSR-7 imutáveis, middleware PSR-15 e respostas que não escrevem na saída antes da borda da aplicação.
  - icon: ⌁
    title: Configuração verificável
    details: Configurações tipadas, validação no bootstrap e caches explícitos de rotas, DTOs, eventos e configuração para produção.
  - icon: ◈
    title: Segurança por padrão
    details: CSRF, headers de segurança, validação, rate limiting, URLs assinadas, uploads seguros e guards de autenticação.
  - icon: ∿
    title: Pronto para processos longos
    details: O mesmo ciclo atende PHP-FPM, FrankenPHP, RoadRunner e Swoole, descartando o estado da requisição ao final de cada execução.
  - icon: ⌘
    title: Ferramentas para trabalhar
    details: CLI para gerar código e inspecionar a aplicação, testes HTTP no processo e fakes para filas, eventos, cache e e-mail.
  - icon: ⊕
    title: Integrações opcionais
    details: NeoORM, OpenTelemetry, RoadRunner, processamento de imagem e utilitários brasileiros entram somente quando o projeto precisa deles.
---

## O mapa da documentação

Comece pelo [guia de instalação](/guide/getting-started), conheça a [estrutura de um projeto](/guide/project-structure) e siga para [controllers](/guide/controllers). A referência detalha os contratos de produção: [roteamento](/ROUTING), [validação](/VALIDATION), [autenticação](/AUTH), [observabilidade](/OBSERVABILITY) e [runtimes](/RUNTIMES).

> [!TIP]
> O NeoFramework Core é a biblioteca. O repositório `NeoFramework` é o skeleton de uma aplicação, e o `NeoORM` é o pacote de persistência. Esta documentação explica como as três peças se encaixam sem confundi-las.
