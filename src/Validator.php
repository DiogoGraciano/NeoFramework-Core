<?php

namespace NeoFramework\Core;

use InvalidArgumentException;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;

class Validator
{
    public static function make(array $fields, array $rules): v
    {
        $validator = new v;

        foreach ($rules as $field => $fieldRules) {
            if (!is_array($fieldRules)) {
                throw new InvalidArgumentException("Rules for the field '{$field}' must be an array.");
            }

            $fieldValidators = [];
            foreach ($fieldRules as $rule) {
                if (is_array($rule)) {
                    if (count($rule) < 1) {
                        throw new InvalidArgumentException("Rule for '{$field}' must have at least the rule name as the first element.");
                    }
                    $methodName = $rule[0];
                    $params = array_slice($rule, 1);
                } else {
                    $methodName = $rule;
                    $params = [];
                }

                if (!method_exists(v::class, $methodName)) {
                    throw new InvalidArgumentException("Rule '{$methodName}' does not exist for the field '{$field}'.");
                }

                $fieldValidators[] = v::$methodName(...$params);
            }
            $validator = $validator->key($field, v::allOf(...$fieldValidators));
        }

        try {
            $validator->assert($fields);
        } catch (NestedValidationException $exception) {
            $erros = $exception->findMessages($messages);
        }


        return $validator;
    }
}
