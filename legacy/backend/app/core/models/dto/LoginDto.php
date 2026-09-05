<?php

namespace app\core\models\dto;

use app\core\models\dto\base\InterfaceDto;

final class LoginDto implements InterfaceDto{

    private $userName, $password;

    public function __construct($data = []){
        $this->setUserName($data["cuenta"] ?? "");
        $this->setPassword($data["clave"] ?? "");
    }

    public function getUsername(): string{
        return $this->userName;
    }

    public function getPassword(): string{
        return $this->password;
    }

    private function setUserName(string $userName): void{
        $this->userName = $userName;
    }

    private function setPassword(string $password): void{
        $this->password = $password;
    }

    public function toArray(): array{
        return [
            "cuenta"  => $this->getUserName(),
            "clave"  => $this->getPassword()
        ];
    }

    public function getId(): int {
        // Return null or throw exception since LoginDto may not have an ID
        return 0;
    }
    
}