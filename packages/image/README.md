# NeoFramework Image GD

Adapter opcional de processamento de imagens para o NeoFramework Core.

```bash
composer require diogodg/neoframework-image-gd
```

`GdImageProcessor` aceita JPEG, PNG, GIF e WebP, corrige a orientação EXIF de
JPEG, preserva a proporção ao limitar as dimensões e sempre reencoda como WebP.
Reencodar remove metadados e qualquer payload que não seja pixel. O GD precisa
decodificar a imagem inteira internamente; defina limites de upload apropriados
antes de chamar o processador.

```php
$processor = new NeoFramework\Image\GdImageProcessor(quality: 82);
$webp = $processor->process($uploadedFile->getStream(), maxWidth: 1920, maxHeight: 1080);
```
