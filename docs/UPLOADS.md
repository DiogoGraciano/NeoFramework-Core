# Uploads

```php
use NeoFramework\Core\Storage\{UploadRules, UploadedFiles};

$stored = (new FileStorage())->storeUploadedFile(
    UploadedFiles::get($request, 'avatar'),
    directory: 'avatars',
    rules: UploadRules::images(maxBytes: 5_000_000),
);

$stored->path;        // /public/assets/avatars/9f3c…_foto.png
$stored->mimeType;    // image/png — detectado, não declarado
$stored->size;        // bytes efetivamente lidos
```

## O que o cliente não decide

**A extensão.** Ela vem do MIME detectado no conteúdo. Um `.php` renomeado para
`.jpg` é recusado pelo conteúdo; um PNG de verdade chamado `exploit.php` é gravado
como `.png`. Um MIME que o Core não sabe nomear é recusado — sem extensão conhecida
não há como afirmar que servir o arquivo é seguro.

**O nome.** O arquivo é gravado sob um identificador aleatório, com um slug do nome
original apenas como sufixo legível.

**O tamanho.** `getSize()` vem do cliente e não é usado para decidir nada. O limite
é aplicado *enquanto* se lê: a leitura para no primeiro byte excedente, de modo que
o custo de recusar não é proporcional ao que o cliente decidiu enviar.

**O diretório.** `../` é recusado antes de qualquer escrita.

## Memória

O conteúdo nunca é materializado. O upload é copiado em blocos de 8 KB para um
temporário local — necessário para detectar o MIME e para aplicar o limite — e
depois transmitido ao disco por stream. O temporário é removido em todos os
caminhos, inclusive quando a validação recusa no meio da cópia.

Uma gravação que falha no meio é apagada do disco: nada fica pela metade e
servível.

## Árvores multipart

`documentos[0][arquivo]` chega aninhado em `getUploadedFiles()`.

```php
UploadedFiles::get($request, 'documentos.0.arquivo');
UploadedFiles::flatten($request); // ['avatar' => …, 'documentos.0.arquivo' => …]
```

## Scanner

`UploadScannerInterface` roda sobre o temporário, depois da validação e antes da
gravação — um veredito negativo significa que nada chegou a ser publicado. O
padrão é `NullUploadScanner`: o Core não embute antivírus.

```php
$storage = new FileStorage(scanner: new ClamAvScanner());
```

## Discos

`DiskInterface` tem `write`, `writeStream`, `readStream`, `exists` e `delete`.
Local, S3 e memória passam pelo mesmo contract test — o S3 contra um MinIO no
`docker-compose`, porque um comportamento que só vale no disco local é uma
armadilha esperando o deploy que troca para S3.

O disco S3 exige as dependências opcionais `aws/aws-sdk-php` e
`league/flysystem-aws-s3-v3`.

## Imagens

O Core só declara `ImageProcessorInterface`; o adapter GD é opcional:

```bash
composer require diogodg/neoframework-image-gd
```

`GdImageProcessor` aceita JPEG, PNG, GIF e WebP, corrige a orientação EXIF de
JPEG, preserva a proporção e sempre devolve WebP. Reencodar remove metadados
EXIF e conteúdo não visual. Como o GD precisa decodificar a imagem inteira,
mantenha `UploadRules::maxBytes` adequado ao limite de memória do processo.
