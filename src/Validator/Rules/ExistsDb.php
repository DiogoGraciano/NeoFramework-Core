<?php

namespace NeoFramework\Core\Validator\Rules;

use Diogodg\Neoorm\Abstract\Model;
use Respect\Validation\Rules\Core\Simple;

class ExistsDb extends Simple
{
    private Model $model;
    private string $field;

    public function __construct(Model $model,string $field = "id"){
        $this->model = $model;
        $this->field = $field;
    }

    public function isValid(mixed $input): bool
    {
       return $this->model->get($input,$this->field)->{$this->field} != null;
    }
}
