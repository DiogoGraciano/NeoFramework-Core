import { defineConfig } from 'vitepress'

const guide = [
  { text: 'Comece aqui', link: '/guide/getting-started' },
  { text: 'Estrutura do projeto', link: '/guide/project-structure' },
  { text: 'Configuração', link: '/guide/configuration' },
  { text: 'Ciclo HTTP', link: '/guide/http-lifecycle' },
  { text: 'Controllers e respostas', link: '/guide/controllers' },
  { text: 'Views e Vite', link: '/guide/views-assets' },
  { text: 'Banco de dados e NeoORM', link: '/guide/database' },
  { text: 'Filas e agendamentos', link: '/guide/queues-scheduling' },
]

export default defineConfig({
  lang: 'pt-BR',
  title: 'NeoFramework',
  description: 'Framework HTTP para PHP 8.4, baseado em PSRs.',
  base: process.env.GITHUB_ACTIONS ? '/NeoFramework-Core/' : '/',
  cleanUrls: true,
  lastUpdated: true,
  ignoreDeadLinks: [
    /IMPLEMENTATION_ROADMAP/,
  ],
  head: [
    ['meta', { name: 'theme-color', content: '#0b1020' }],
    ['meta', { property: 'og:title', content: 'NeoFramework — documentação' }],
    ['meta', { property: 'og:description', content: 'Framework HTTP para PHP 8.4, baseado em PSRs.' }],
    ['link', { rel: 'icon', href: '/NeoFramework-Core/favicon.svg' }],
  ],
  themeConfig: {
    logo: '/favicon.svg',
    siteTitle: 'NeoFramework',
    nav: [
      { text: 'Guia', link: '/guide/getting-started', activeMatch: '^/guide/' },
      { text: 'Referência', link: '/ROUTING', activeMatch: '^/(ROUTING|VALIDATION|RESPONSES|AUTH|RATE_LIMITING|UPLOADS|EVENTS|OBSERVABILITY|RUNTIMES|TESTING|COMPATIBILITY)' },
      { text: 'GitHub ↗', link: 'https://github.com/DiogoGraciano/NeoFramework-Core' },
    ],
    sidebar: [
      {
        text: 'Fundamentos',
        items: guide,
      },
      {
        text: 'Referência HTTP',
        collapsed: false,
        items: [
          { text: 'Roteamento', link: '/ROUTING' },
          { text: 'Validação e DTOs', link: '/VALIDATION' },
          { text: 'Respostas e streaming', link: '/RESPONSES' },
          { text: 'Autenticação e autorização', link: '/AUTH' },
          { text: 'Rate limiting', link: '/RATE_LIMITING' },
          { text: 'Uploads e imagens', link: '/UPLOADS' },
        ],
      },
      {
        text: 'Operação',
        collapsed: false,
        items: [
          { text: 'Eventos', link: '/EVENTS' },
          { text: 'Observabilidade', link: '/OBSERVABILITY' },
          { text: 'Testes', link: '/TESTING' },
          { text: 'Runtimes persistentes', link: '/RUNTIMES' },
          { text: 'Compatibilidade', link: '/COMPATIBILITY' },
          { text: 'Roadmap de implementação', link: '/IMPLEMENTATION_ROADMAP' },
        ],
      },
    ],
    socialLinks: [
      { icon: 'github', link: 'https://github.com/DiogoGraciano/NeoFramework-Core' },
    ],
    search: { provider: 'local' },
    outline: { label: 'Nesta página', level: [2, 3] },
    editLink: {
      pattern: 'https://github.com/DiogoGraciano/NeoFramework-Core/edit/main/docs/:path',
      text: 'Editar esta página no GitHub',
    },
    footer: {
      message: 'Distribuído sob a licença MIT.',
      copyright: 'Copyright © 2026 Diogo Graciano',
    },
  },
})
