<?php
namespace app\core\exceptions;

final class AuthenticationException extends HttpException {
    protected int $status = 401;
}   
