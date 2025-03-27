<?php

namespace NeoFramework\Core\Validator\Exception;
use Respect\Validation\Exceptions\ValidationException;

class ExistsDbException extends ValidationException
{
    protected $defaultTemplates = [
        self::MODE_DEFAULT => [
            self::STANDARD => 'Value not exists',
        ],
        self::MODE_NEGATIVE => [
            self::STANDARD => 'Value not exists',
        ],
    ];
}
