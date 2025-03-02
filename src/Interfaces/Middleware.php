<?php

namespace NeoFramework\Core\Interfaces;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Response;

interface Middleware 
{
    public function before(Controller $controller):Controller;

    public function after(Response $response):Response;
}
