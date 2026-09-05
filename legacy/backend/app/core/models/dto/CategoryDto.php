<?php

namespace app\core\models\dto;

use app\core\models\dto\base\InterfaceDto;

final class CategoryDto implements InterfaceDto{

    private $id, $nombre;

    public function __construct(array $data = [])
    {
        //Operador coalescente null
        $this->setId($data["id"] ?? 0);
        $this->setNombre($data["nombre"] ?? "");
    }

    public function getId(): int{
        return $this->id;
    }

    public function getNombre(): string{
        return $this->nombre;
    }

    public function setId(int $id): void{
        $this->id = $id > 0 ? $id : 0;
    }

    // VALIDAR LA LONGITUD DEL STRING Tiene que ser menor o igual a 100
    public function setNombre(string $nombre): void{
        $this->nombre = (strlen(trim($nombre)) <= 100) ? trim($nombre) : "";
    }

    public function toArray(): array{
        return [
            "id"        => $this->getId(),
            "nombre"    => $this->getNombre()
        ];
    }

}