<?php
namespace app\core\exceptions;

class HttpException extends \Exception {
    protected int $status = 500;

    public function getStatus(): int { 
        return $this->status; 
    }
}