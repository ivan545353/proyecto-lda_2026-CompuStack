<?php

namespace app\core\models\dto;

use app\core\models\dto\base\InterfaceDto;

final class ItemDto implements InterfaceDto {

    private $id, $nombre, $codigo, $descripcion, $categoriaId, $categoria, $precio, $stock;

    /**
     * El constructor recibe un array asociativo con los datos del producto.
     * Si no se proporciona un valor, se asigna un valor por defecto.
     * 
     * @param array $data Array asociativo con los datos del producto.
     */
    public function __construct(array $data = []) {
        $this->setId($data["id"] ?? 0);
        $this->setNombre($data["nombre"] ?? "");
        $this->setCodigo($data["codigo"] ?? "");
        $this->setDescripcion($data["descripcion"] ?? "");
        $this->setCategoriaId($data["categoriaId"] ?? 0);
        $this->setCategoria($data["categoria"] ?? "");
        $this->setPrecio($data["precio"] ?? 9999999);
        $this->setStock($data["stock"] ?? 0);
    }

    /***********************************************************
     * **********GETTERS DE CADA ATRIBUTO*********************
     ********************************************************/

    public function getId(): int {
        return $this->id;
    }

    public function getNombre(): string {
        return $this->nombre;
    }

    public function getCodigo(): string {
        return $this->codigo;
    }

    public function getDescripcion(): string {
        return $this->descripcion;
    }

    public function getCategoriaId(): int {
        return $this->categoriaId;
    }

    public function getCategoria(): string {
        return $this->categoria;
    }

    public function getPrecio(): float {
        return $this->precio;
    }

    public function getStock(): int {
        return $this->stock;
    }

    /***********************************************************
     * **********SETTERS DE CADA ATRIBUTO*********************
     ********************************************************/

    public function setId(int $id): void {
        $this->id = $id > 0 ? $id : 0;
    }

    public function setNombre(string $nombre): void {
        $this->nombre = (strlen(trim($nombre)) <= 100) ? trim($nombre) : "";
    }

    public function setCodigo(string $codigo): void {
        $this->codigo = (strlen(trim($codigo)) <= 25) ? trim($codigo) : "";
    }

    public function setDescripcion(string $descripcion): void {
        $this->descripcion = (strlen(trim($descripcion)) <= 255) ? trim($descripcion) : "";
    }

    public function setCategoriaId(int $categoriaId): void {
        $this->categoriaId = $categoriaId > 0 ? $categoriaId : 0;
    }

    public function setCategoria(string $categoria): void {
        $this->categoria = trim($categoria);
    }

    public function setPrecio(float $precio): void {
        $this->precio = $precio >= 0 ? $precio : 0;
    }

    public function setStock(int $stock): void {
        $this->stock = $stock >= 0 ? $stock : 0;
    }

    /* METODO DE InterfaceDto */
    public function toArray(): array {
        return [
            "id"           => $this->getId(),
            "nombre"       => $this->getNombre(),
            "codigo"       => $this->getCodigo(),
            "descripcion"  => $this->getDescripcion(),
            "categoriaId"  => $this->getCategoriaId(),
            "categoria"    => $this->getCategoria(),
            "precio"       => $this->getPrecio(),
            "stock"        => $this->getStock()
        ];
    }
}
