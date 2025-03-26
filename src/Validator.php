<?php

namespace NeoFramework\Core;

class Validator
{
    public function validateRequest(Request $request,array $rules){
        $all = $request->all();
    }
}
