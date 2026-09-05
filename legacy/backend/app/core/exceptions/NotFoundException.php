<?php
namespace app\core\exceptions;

final class NotFoundException extends HttpException {
    protected int $status = 404;
}   