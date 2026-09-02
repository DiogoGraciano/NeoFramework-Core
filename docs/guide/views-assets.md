# Views e assets

O sistema de templates mantém HTML da aplicação fora do controller. Layouts definem a moldura, templates definem o conteúdo e o Vite fornece CSS e JavaScript em desenvolvimento e produção.

## Layout e template

O skeleton fornece um layout em `App/View/Layout/Main.php` e um template em `App/View/Templates/main.html`:

```php
use NeoFramework\Core\Abstract\Layout;

final class Main extends Layout
{
    public function __construct(string $title = 'Minha aplicação', string $content = '')
    {
        $this->setTemplate('main.html');
        $this->tpl->TITLE = $title;
        $this->tpl->CONTENT = $content;
    }
}
```

Os tokens do template usam chaves delimitadas, como `{TITLE}` e `{CONTENT}`. Mantenha a escolha de layout perto da camada web; serviços de domínio devem devolver dados, não HTML.

## Vite

`Config/vite.config.php` declara os entrypoints que `{neof_vite}` injeta no layout. Eles precisam corresponder aos inputs de `vite.config.js`:

```php
return [
    'entrypoints' => [
        'resources/css/app.css',
        'resources/js/app.js',
    ],
    'build_path' => 'build',
    'hot_file' => 'hot',
];
```

Durante o desenvolvimento, inicie o Vite no projeto da aplicação. O hot file faz o framework apontar para o servidor de desenvolvimento; em produção, o manifest associa cada entrada ao arquivo versionado no diretório `public/build`.

```bash
npm install
npm run dev
npm run build
```

Se uma área usa entradas próprias, declare-as no layout por meio de `$viteEntrypoints`. O framework não adivinha imports para não enviar bundles desnecessários a toda página.

## Produção

O build de assets é parte do deploy. Publique `public/build` junto com a aplicação e ative Vite em produção somente quando o manifest estiver presente. O manifest e os arquivos gerados pertencem ao mesmo release para evitar HTML novo apontando a hashes antigos.
