<?php
declare(strict_types=1);
namespace NeoFramework\Core\Exceptions;

final class NotFoundException extends HttpException { public function __construct() { parent::__construct(404, 'Not Found'); } }
