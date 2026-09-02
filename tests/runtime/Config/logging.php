<?php
declare(strict_types=1);
// stderr: em container o arquivo dentro do worker se perde no restart.
return ['channel' => 'runtime', 'level' => 'error', 'format' => 'json', 'stream' => 'stderr', 'path' => 'Logs/system.log'];
