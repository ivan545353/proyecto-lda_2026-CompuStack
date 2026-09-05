<?php
namespace app\core\exceptions;

final class AuthorizationException extends HttpException {
    protected int $status = 403;
}   
