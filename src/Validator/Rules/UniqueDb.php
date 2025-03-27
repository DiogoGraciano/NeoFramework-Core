<?php

namespace NeoFramework\Core\Validator\Rules;

use Respect\Validation\Rules\Core\Simple;
use Diogodg\Neoorm\Abstract\Model;

class UniqueDb extends Simple
{
    private Model $model;
    private string $field;

    public function __construct(Model $model,string $field){
        $this->model = $model;
        $this->field = $field;
    }

    public function isValid(mixed $input): bool
    {
        return $this->model->get($input,$this->field)->{$this->field} == null;
    }
}
