<?php
declare(strict_types=1);
namespace NeoFramework\Core\Exceptions;

final class ValidationException extends HttpException { /** @param array<string,list<string>> $errors */ public function __construct(public readonly array $errors) { parent::__construct(422, 'Validation failed'); } }
