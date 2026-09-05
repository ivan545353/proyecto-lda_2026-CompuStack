<?php

namespace app\libs\pipeline;

use app\libs\pipeline\middlewares\base\InterfaceMiddleware;
use app\libs\http\Request;
use app\libs\http\Response;

final class Pipeline{

    private ?InterfaceMiddleware $first, $last;

    public function __construct(){
        $this->first = $this->last = null;
    }

    /**
     * Agrega un middleware en la tuberia.
     * Se guardan en una lista, al final de la misma.
     * @param InterfaceMiddleware $middleware Nuevo middleware.
     * @return $this se retorna el objeto a si mismo, para poder ejecutar métodos encadenados.
     */
    public function pipe(InterfaceMiddleware $middleware){
        if ($this-> first == null){
            $this->first = $this->last = $middleware;
        }
        else{
            $this->last->setNext($middleware);
            $this->last = $middleware;
        }

        return $this;
    }

    /**
     * Ejecuta el primer middleware de la lista.
     * @param Request $request Petición del cliente.
     */
    public function process(Request $request, Response $response){
        if($this->first != null){
            $this->first->handler($request, $response);
        }
    }
}