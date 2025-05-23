<?php
namespace NeoFramework\Core\Abstract;

use DI\Attribute\Inject;
use NeoFramework\Core\Functions;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;

abstract class Controller
{
    protected readonly int $page;

    protected readonly array $urlQuery;

    #[Inject(Request::class)]
    protected Request $request;

    #[Inject(Response::class)]
    protected Response $response;

    const validCsrfToken = true;

    public function __construct()
    {
        if(!isset($this->request)){
            $this->request = new Request();
        }

        if(!isset($this->response)){
            $this->response = new Response();
        }

        $this->urlQuery = $this->request->getArray();
        $this->page = $this->request->get("page") ?? 1;
        
    }

    public function setResquest(Request $request){
        $this->request = $request;

        return $this;
    }

    public function setResponse(Response $response){
        $this->response = $response;

        return $this;
    }

    public function getResponse(){
        return $this->response;
    }

    public function getRequest(){
        return $this->request;
    }

    protected function getOffset(int $limit = 30):int
    {
        return ($this->page-1)*$limit;
    }

    protected function getLimit(int $limit = 30):int
    {
        return $limit?:20;
    }

    protected function isMobile():bool
    {
        return Functions::isMobile();
    }
}
