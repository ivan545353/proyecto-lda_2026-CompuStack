<?php

namespace app\libs\pipeline\middlewares\base;

use app\libs\pipeline\middlewares\base\InterfaceMiddleware;
use app\libs\http\Request;
use app\libs\http\Response;

/**
 * Descripción de middleware
 * Clase base, que sera heredada por los middlewares de la aplicación.
 * Implementa el patron de comportamiento: Chain of Responsability.
 * 
 * @author Reales Daniel Ivan
 */

 class BaseMiddleware{

    protected ?InterfaceMiddleware $next;

    public function __construct(){
        $this->next = null;
    }

    public function setNext(InterfaceMiddleware $middleware): void{
        $this->next = $middleware;
    }

    public function handlerNext(Request $request, Response $response): void{
        if($this->next != null){
            $this->next->handler($request,$response);
        }
    }
    
 }