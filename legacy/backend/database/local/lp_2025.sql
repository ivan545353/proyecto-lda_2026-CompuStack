-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 07-07-2025 a las 01:56:14
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `lp_2025`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categorias`
--

CREATE TABLE `categorias` (
  `id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `categorias`
--

INSERT INTO `categorias` (`id`, `nombre`) VALUES
(33, 'Accesorios'),
(29, 'Discos HDD'),
(7, 'Fuentes'),
(3, 'Gabinetes'),
(27, 'Impresoras'),
(5, 'Memorias'),
(1, 'Monitores'),
(30, 'Motherboards'),
(2, 'Perifericos'),
(6, 'Placas de video'),
(9, 'RAM');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos`
--

CREATE TABLE `productos` (
  `id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `codigo` varchar(25) NOT NULL,
  `descripcion` text NOT NULL,
  `categoriaId` int(10) UNSIGNED NOT NULL,
  `precio` float(12,2) NOT NULL,
  `stock` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `productos`
--

INSERT INTO `productos` (`id`, `nombre`, `codigo`, `descripcion`, `categoriaId`, `precio`, `stock`) VALUES
(1, 'ASUS NVIDIA RTX 5090', 'RTX 5090', 'Placa de videojuegos gama', 6, 4000.00, 0),
(2, 'Monitor VG248QG', 'VG248QG', 'Monitor 165hz para videojuegos super picante', 1, 500000.00, 0),
(3, 'Mouse Logitech G505', 'G505', 'Mouse inalámbrico de alta respuesta para videojuegos', 2, 40000.00, 30),
(30, 'HDD Seagate Barracuda 1TB', 'HDD-SEA1TB', 'Disco duro confiable para almacenamiento masivo', 29, 30000.00, 10),
(31, 'Mother ASUS B450M', 'MB-ASUSB450', 'Placa madre compatible con Ryzen', 30, 60000.00, 15),
(36, 'Impresora HP 2775', 'HP-2775', 'Impresora multifunción económica', 27, 85000.00, 7),
(37, 'Auriculares HyperX Cloud Stinger', 'HX-STINGER', 'Auriculares de calidad para juegos', 33, 45000.00, 18),
(39, 'Micrófono Fifine K669B', 'MIC-K669B', 'Micrófono condensador USB', 33, 28000.00, 22),
(41, 'Gabinete Corsair 4000D', 'COR-4000D', 'Gabinete ATX de alto flujo de aire', 3, 80000.00, 5),
(42, 'Fuente EVGA 600W 80+', 'EVGA-600', 'Fuente certificada para PCs gamers', 7, 45000.00, 15);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(10) UNSIGNED NOT NULL,
  `apellido` varchar(100) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `cuenta` varchar(20) NOT NULL,
  `perfil` enum('Administrador','Operador') NOT NULL,
  `clave` varchar(255) NOT NULL,
  `correo` varchar(255) NOT NULL,
  `estado` tinyint(1) NOT NULL,
  `fechaAlta` date NOT NULL,
  `resetPass` tinyint(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `apellido`, `nombres`, `cuenta`, `perfil`, `clave`, `correo`, `estado`, `fechaAlta`, `resetPass`) VALUES
(1, 'Reales', 'Daniel Ivan', 'ivan5453', 'Administrador', '$2y$10$7DHsqRk7HvGS5KBOnif1YuM7lIcR4npiAikvaDMn6DwvgVJj0OrqG', 'ivan545353@gmail.com', 1, '2025-07-02', 0),
(2, 'Salina', 'Luna Antonella', 'luna5453', 'Operador', '$2y$10$HW4XBgTmw681REUxou4AhuEJLSFCP/lc/hxpJ5Rq46aOqDPO06BrO', 'luna545353@gmail.com', 1, '2025-07-05', 0),
(3, 'Colque', 'Pedro Valentin', 'pepe5453', 'Operador', '$2y$10$ARMfeJ8ebV3WpCTMZjwEw.4v20djcA16wpydMriC9CfKl2kjxRqdC', 'pepe545353@gmail.com', 1, '2025-07-06', 0),
(4, 'Robledo', 'Alan Nicolas', 'robledo5453', 'Operador', '$2y$10$9U9MCbkMPR3Ghvhbz1EDR.lL1ZXk0csFmEt72/639IN5kMXQty2xS', 'robledo545353@gmail.com', 1, '2025-07-06', 0),
(5, 'López', 'Martín', 'martin123', 'Operador', '$2b$12$Cdm5qhVXlT72jYDuisIFEeO6E1MoJj8M0dmcGck3bT/4.0gusnHMS', 'martin@gmail.com', 1, '2025-07-06', 0),
(6, 'Fernández', 'Laura', 'laura456', 'Administrador', '$2b$12$Cdm5qhVXlT72jYDuisIFEeO6E1MoJj8M0dmcGck3bT/4.0gusnHMS', 'laura@gmail.com', 1, '2025-07-06', 0),
(7, 'Gómez', 'Carlos', 'carlos789', 'Operador', '$2b$12$Cdm5qhVXlT72jYDuisIFEeO6E1MoJj8M0dmcGck3bT/4.0gusnHMS', 'carlos@gmail.com', 1, '2025-07-06', 0),
(8, 'Ramírez', 'Sofía', 'sofia001', 'Administrador', '$2b$12$Cdm5qhVXlT72jYDuisIFEeO6E1MoJj8M0dmcGck3bT/4.0gusnHMS', 'sofia@gmail.com', 1, '2025-07-06', 0),
(9, 'Martínez', 'Joaquín', 'joaquin2025', 'Operador', '$2b$12$Cdm5qhVXlT72jYDuisIFEeO6E1MoJj8M0dmcGck3bT/4.0gusnHMS', 'joaquin@gmail.com', 1, '2025-07-06', 0),
(11, 'Pérez', 'Tomás', 'tomas777', 'Operador', '$2b$12$Cdm5qhVXlT72jYDuisIFEeO6E1MoJj8M0dmcGck3bT/4.0gusnHMS', 'tomas@gmail.com', 0, '2025-07-06', 0),
(12, 'Rodríguez', 'Lucía', 'lucia007', 'Administrador', '$2b$12$Cdm5qhVXlT72jYDuisIFEeO6E1MoJj8M0dmcGck3bT/4.0gusnHMS', 'lucia@gmail.com', 1, '2025-07-06', 0),
(13, 'Gutiérrez', 'Andrés', 'andres963', 'Operador', '$2b$12$Cdm5qhVXlT72jYDuisIFEeO6E1MoJj8M0dmcGck3bT/4.0gusnHMS', 'andres@gmail.com', 1, '2025-07-06', 0);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `categorias_unique` (`nombre`);

--
-- Indices de la tabla `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `productos_unique` (`codigo`),
  ADD UNIQUE KEY `productos_nombre_IDX` (`nombre`,`categoriaId`) USING BTREE,
  ADD KEY `productos_categorias_FK` (`categoriaId`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuarios_unique` (`cuenta`),
  ADD UNIQUE KEY `usuarios_unique_1` (`correo`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT de la tabla `productos`
--
ALTER TABLE `productos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `productos`
--
ALTER TABLE `productos`
  ADD CONSTRAINT `productos_categorias_FK` FOREIGN KEY (`categoriaId`) REFERENCES `categorias` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
