<?php

namespace NeoFramework\Core\Validator\Exception;
use Respect\Validation\Exceptions\ValidationException;

class UniqueDbException extends ValidationException
{
    protected $defaultTemplates = [
        self::MODE_DEFAULT => [
            self::STANDARD => 'Duplicate value',
        ],
        self::MODE_NEGATIVE => [
            self::STANDARD => 'Duplicate value',
        ],
    ];
}
