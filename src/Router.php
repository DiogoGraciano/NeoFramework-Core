<?php
namespace NeoFramework\Core;

use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Attributes\Middleware;
use NeoFramework\Core\Middleware\Cors;
use NeoFramework\Core\Middleware\SecurityHeaders;
use NeoFramework\Core\Exceptions\HttpResponseException;
use DI\Container;
use NeoFramework\Core\Container as CoreContainer;
use Exception;
use ReflectionAttribute;
use ReflectionClass;

final class Router{
   
    private string $uri;

    private array $folders = [];

    private array $routesRewrite = [];

    private string $namespace = "";

    private string $controller = "";

    private Container $container;

    private array $globalMiddlewares = [];

    public function __construct()
    {
        $this->uri = Url::getUriPath();
        $this->container = (new CoreContainer())->load();
        $this->getFolders();
        $this->getRouteRewrite();
        $this->loadGlobalMiddlewares();
    }

    private function loadGlobalMiddlewares(): void
    {
        if ($this->isCorsEnabled()) {
            $this->globalMiddlewares[] = Cors::fromEnv();
        }

        if ($this->isSecurityHeadersEnabled()) {
            $this->globalMiddlewares[] = SecurityHeaders::fromEnv();
        }
    }

    private function isCorsEnabled(): bool
    {
        return filter_var(env('CORS_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function isSecurityHeadersEnabled(): bool
    {
        return filter_var(env('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Métodos HTTP que, por definição, não alteram estado e por isso não exigem
     * token CSRF.
     */
    private const SAFE_HTTP_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Decide se a requisição atual precisa apresentar um token CSRF válido.
     *
     * A isenção é decidida por requisição, e não pela rota inteira: uma rota que
     * aceita GET e POST continua exigindo token no POST.
     */
    private function requiresCsrfValidation(object $controller, Route $routeAttribute, Request $request): bool
    {
        if ($controller::skipCsrfValidation || !$routeAttribute->getValidCsrf()) {
            return false;
        }

        $method = strtoupper((string) $request->getMethod());

        return !in_array($method, self::SAFE_HTTP_METHODS, true);
    }

    private function getRouteRewrite(){
        if(file_exists(Functions::getRoot()."Config/route_rewrite.config.php")){
            $this->routesRewrite = include_once Functions::getRoot()."Config/route_rewrite.config.php";
        }
    }

    private function getFolders(){
        $folder = Functions::getRoot()."App/Controllers";

        // A raiz também é um namespace válido: controllers podem viver direto
        // em App/Controllers, sem subpasta.
        $this->folders[] = "App\Controllers";

        if (!is_dir($folder)) {
            return;
        }

        $files = scandir($folder);

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (is_dir($folder . DIRECTORY_SEPARATOR . $file))
                $this->folders[] = "App\Controllers\\".$file;
        }
    }

    public function getNamespace(){
        return $this->namespace;
    }

    public function load(string|bool $controller = false){

        if ($controller){
            return $this->controllerSet($controller); 
        }
        
        if($this->isHome())
            return $this->controllerHome();
        
        return $this->controllerNotHome();
    }

    private function controllerHome(){
        if (!$this->controllerExist('home')){
            throw new Exception("The page you are looking for does not exist.",404);
        }
      
        return $this->instatiateController();
    }

    private function controllerSet($controller){
        if (!$this->controllerExist($controller)){
            throw new Exception("The page you are looking for does not exist.",404);
        }
        
        return $this->instatiateController();
    }

    private function controllerNotHome(){
        $controller = $this->getControllerNotHome();

        if (!$this->controllerExist($controller)){
            throw new Exception("The page you are looking for does not exist.",404);
        }
        
        return $this->instatiateController();
    }

    public function getControllerNotHome(){

        if(substr_count($this->uri,'/') > 1){
            list($controller) = array_values(array_filter(explode('/',$this->uri)));
            return (($controller));
        }
        return ((ltrim($this->uri,"/")));
    }

    private function controllerExist($controller){
        if ($this->checkRouteRewrite($controller)) {
            return true;
        }

        return $this->findControllerInFolders($controller);
    }

    private function checkRouteRewrite($controller): bool
    {
        // O mapa é indexado pelo nome da rota; procurar nos valores nunca casava
        // com a chave usada logo abaixo.
        if (!isset($this->routesRewrite[$controller])) {
            return false;
        }

        $rewriteClass = $this->routesRewrite[$controller];
        if (!class_exists($rewriteClass)) {
            return false;
        }

        $this->namespace = (new \ReflectionClass($rewriteClass))->getNamespaceName();
        $this->controller = $controller;
        return true;
    }

    private function findControllerInFolders($controller): bool
    {
        // O mapa evita a varredura por diretórios e a sequência de class_exists
        // a cada requisição. Em desenvolvimento ele é reconstruído na hora.
        $map = RouteCache::get($this->folders);
        $cached = $map['controllers'][strtolower((string) $controller)] ?? null;

        if ($cached !== null && $this->isValidController($cached)) {
            $this->setResolvedController($cached);
            return true;
        }

        $controllerName = ucfirst($controller);

        foreach ($this->folders as $folder) {
            $possibleControllers = [
                $folder . '\\' . $controllerName,
                $folder . '\\' . $controllerName . 'Controller'
            ];

            foreach ($possibleControllers as $fullClassName) {
                if ($this->isValidController($fullClassName)) {
                    $this->setResolvedController($fullClassName);
                    return true;
                }
            }
        }

        return false;
    }

    private function setResolvedController(string $fullClassName): void
    {
        $position = strrpos($fullClassName, '\\');

        $this->namespace = $position === false ? '' : substr($fullClassName, 0, $position);
        $this->controller = $position === false ? $fullClassName : substr($fullClassName, $position + 1);
    }

    private function isValidController($className): bool
    {
        return class_exists($className) && is_subclass_of($className, 'NeoFramework\Core\Abstract\Controller');
    }

    /**
     * Métodos a examinar em busca da rota.
     *
     * Com o mapa disponível, apenas os métodos que declaram uma rota são
     * inspecionados; sem ele, cai para todos os métodos públicos. Os atributos
     * continuam sendo instanciados por Reflection, então um mapa desatualizado
     * apenas perde a otimização — nunca despacha a rota errada.
     *
     * @return array<int,\ReflectionMethod>
     */
    private function candidateMethods(ReflectionClass $reflection): array
    {
        $map = RouteCache::get($this->folders);
        $declared = $map['routes'][$reflection->getName()] ?? null;

        if (!$declared) {
            return $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        }

        $methods = [];

        foreach ($declared as $route) {
            if ($reflection->hasMethod($route['method'])) {
                $methods[] = $reflection->getMethod($route['method']);
            }
        }

        return $methods ?: $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
    }
    
    private function instatiateController(){

        $controller = $this->namespace.'\\'.$this->controller;
        $controller =  $this->container->get($controller);

        $ReflectionClass = new ReflectionClass($controller);
        $methods = $this->candidateMethods($ReflectionClass);

        $routeAttribute = null;
        $uri = null;
        $parameters = null;
        $middlewareAttribute = null;

        foreach ($methods as $method){
            
            $routeAttribute = $method->getAttributes(Route::class,ReflectionAttribute::IS_INSTANCEOF);
            $middlewareAttribute = $method->getAttributes(Middleware::class,ReflectionAttribute::IS_INSTANCEOF);

            if(isset($routeAttribute[0])){
                $routeAttribute = $routeAttribute[0]->newInstance();
            }
            else{
                continue;
            }

            if(isset($middlewareAttribute[0])){
                $middlewareAttribute = $middlewareAttribute[0]->newInstance();
            }
    
            $httpMethods = $routeAttribute->getMethods();

            if($this->isCorsEnabled() && !in_array("OPTIONS",$httpMethods)){
                $httpMethods[] = "OPTIONS";
            }

            $uri = $routeAttribute->getPath();
            $uri = explode("/",$uri);
            $path = $uri[0];
            unset($uri[0]); 

            if($path == $this->getPath() && in_array($_SERVER['REQUEST_METHOD'],$httpMethods)){
                $parameters = $this->getParameters($uri);
                break;
            }else{
                $routeAttribute = null;
                $uri = null;
                $parameters = null;
                $middlewareAttribute = null;
            }
        }    

        if(!$routeAttribute){
            throw new Exception("The page you are looking for does not exist.",404);
        }

        $methodName = $method->getName();

        Session::set("controller_namespace",$this->namespace); 
        Session::set("controller",$controller::class);

        $initialResponse = new Response;
        $request = new Request;

        if($this->requiresCsrfValidation($controller,$routeAttribute,$request) && !Session::validateCsrfToken($request->getCsrfToken())){
            $initialResponse->setCode(403)->addContent("Invalid or missing CSRF token.")->send();
            return;
        }

        $controller->setResquest($request);
        $controller->setResponse($initialResponse);

        try {
            foreach ($this->globalMiddlewares as $globalMiddleware) {
                $controller = $globalMiddleware->before($controller);
            }

            if($middlewareAttribute){
                $controller = $middlewareAttribute->handleBefore($controller);
            }

            $response = $controller->$methodName(...$parameters);

            if(!is_a($response,"NeoFramework\Core\Response"))
                throw new Exception("The return of a controller method must be an instance of the Response method.");

            // Um controller que devolve uma Response nova não pode descartar os
            // cabeçalhos que os middlewares "before" já definiram.
            $response->mergeHeadersFrom($initialResponse);

            if($middlewareAttribute){
                $response = $middlewareAttribute->handleAfter($response);
            }

            foreach (array_reverse($this->globalMiddlewares) as $globalMiddleware) {
                $response = $globalMiddleware->after($response);
            }
        } catch (HttpResponseException $interruption) {
            // Um middleware encerrou o fluxo (preflight CORS, autorização negada)
            $interruption->getResponse()->send();
            return;
        }

        $response->send();
    }

    public function getParameters(array $uriParameters):array
    {
        $uriParameters = array_values($uriParameters);
        
        $parameter = array_slice(array_values(explode('/',$this->uri)),3);
    
        $parametersFinal = [];

        foreach ($uriParameters as $key => $uriParameter){
            $uriArray = \explode(":",str_replace(["{","}"],"",$uriParameter));

            $var = null;
            $required = true;
            if(isset($uriArray[1]))
                $var = $uriArray[1];
            if(isset($uriArray[2]) && $uriArray[2] == "optional")
                $required = false;

            if(isset($parameter[$key]) && !is_null($var) && !empty($parameter[$key])){
                if($var == "num" && is_numeric($parameter[$key])){
                    $parametersFinal[] = $parameter[$key];
                }
                elseif($var == "any" && is_string($parameter[$key])){
                    $parametersFinal[] = $parameter[$key];
                }
                elseif(@preg_match("/".$var."/",'') == false){
                    if(preg_match($var,$parameter[$key]))
                        $parametersFinal[] = $parameter[$key];
                    else
                        throw new Exception(($key+1)."° parameter is invalid.",500);
                }
                else{
                    throw new Exception(($key+1)."° parameter is invalid.",500);
                }
            }
            elseif($required){
                throw new Exception(($key+1)."° parameter is invalid.",500);
            }
        }

        return $parametersFinal;
    }

    private function getPath(){

        if (substr_count($this->uri,'/') > 1){
            $method = array_values(array_filter(explode('/',$this->uri)));
            if (array_key_exists(1,$method))
                return $method[1];
        }

        return "index";
    }

    private function isHome(){
        return ($this->uri == "/");    
    }
}