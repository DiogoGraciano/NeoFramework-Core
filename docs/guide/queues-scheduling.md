# Filas e agendamentos

Filas retiram trabalho lento da resposta HTTP; agendamentos disparam trabalho recorrente. Os dois existem para que uma requisição seja curta, previsível e observável.

## Jobs

Crie um job com a CLI e coloque nele somente os dados necessários para executar a tarefa:

```bash
php neof make:job SendReceipt
```

Configure o driver em `Config/queue.php`. O driver `files` é útil no desenvolvimento; Redis é a escolha apropriada quando vários processos precisam disputar jobs de maneira coordenada. A configuração inclui TTL de job e de lock, que impedem um worker interrompido de reter uma tarefa para sempre.

Não passe conexões, objetos de request ou entidades carregadas ao job. Serialize identificadores e recarregue o estado atual dentro do worker — o job pode executar minutos depois, em outro processo.

## Agendador

Declare tarefas em `schedule.php` na raiz do projeto:

```php
<?php

use NeoFramework\Core\Scheduler;

Scheduler::call(static function (): void {
    // trabalho recorrente
})->everyMinute();
```

Execute o arquivo pela CLI a cada minuto pelo agendador do sistema:

```cron
* * * * * cd /caminho/do/projeto && php neof schedule:run >> /dev/null 2>&1
```

`schedule:run` carrega a agenda e processa o que está vencido. Para um processo persistente, `php neof schedule:work` mantém o scheduler ativo. Deixe o scheduler do sistema chamar a aplicação em vez de manter lógica de calendário espalhada em scripts shell; isso centraliza logs, configuração e testes.

## Workers

Um worker de fila é um processo separado do servidor HTTP:

```bash
php neof queue:work default
```

Gerencie esse processo com Supervisor, systemd, Docker ou a plataforma de deploy. Reinicie-o a cada release para que carregue o código e a configuração da nova versão.

## Falhas e eventos

O ciclo de fila emite `JobProcessing`, `JobProcessed` e `JobFailed`. `JobFailed` informa se haverá retry, para que alertas não tratem uma falha transitória como perda definitiva. Registre listeners em `Config/events.php` e consulte o catálogo completo em [eventos](/EVENTS).

Nos testes, use `FakeQueue`: ele evita Redis ou disco e permite afirmar qual job foi enfileirado, com quais argumentos e quantas vezes. A API está detalhada em [testes](/TESTING).
