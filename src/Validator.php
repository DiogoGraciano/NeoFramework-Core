<?php

namespace NeoFramework\Core;

use InvalidArgumentException;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;

class Validator
{
    private bool $hasErrors = false;
    private array $errors = [];

    public function make(array $fields, array $rules,array $messages = []):self
    {
        $validator = new v;

        foreach ($rules as $field => $fieldRules) {
            if (!is_object($fieldRules) && $fieldRules::class != "Respect\Validation\Validator") {
                throw new InvalidArgumentException("Rules for the field '{$field}' must be an instace of Respect\Validation\Validator.");
            }

            $validator = $validator->key($field, $fieldRules);
        }

        try {
            $validator->assert($fields);
        } catch (NestedValidationException $exception) {
            $errorsOrigin = $exception->getMessages();
            $fields = array_keys($errorsOrigin);
            
            $errors = [];
            foreach ($fields as $field){
                if(isset($messages[$field])){
                    $errors[$field] = $messages[$field];
                }
                else{
                    $errors[$field] = $errorsOrigin[$field];
                }
                    
            }

            $this->hasErrors = true;
            $this->errors = $errors;
        }

        return $this;
    }

    public function hasError(){
        return $this->hasErrors;
    }

    public function getErrors(){
        return $this->errors;
    }
}
