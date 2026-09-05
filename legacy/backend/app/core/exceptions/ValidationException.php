<?php
namespace app\core\exceptions;

final class ValidationException extends HttpException {
    protected int $status = 400; 
}
