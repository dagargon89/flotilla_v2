-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 11, 2025 at 10:45 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `flotilla_interna`
--

-- --------------------------------------------------------

--
-- Table structure for table `amonestaciones`
--

CREATE TABLE `amonestaciones` (
  `id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `fecha_amonestacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tipo_amonestacion` enum('leve','grave','suspension') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `evidencia_url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amonestado_por` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `documentos_vehiculos`
--

CREATE TABLE `documentos_vehiculos` (
  `id` int NOT NULL,
  `vehiculo_id` int NOT NULL,
  `nombre_documento` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ruta_archivo` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `fecha_subida` datetime DEFAULT CURRENT_TIMESTAMP,
  `subido_por` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `historial_uso_vehiculos`
--

CREATE TABLE `historial_uso_vehiculos` (
  `id` int NOT NULL,
  `solicitud_id` int NOT NULL,
  `vehiculo_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `kilometraje_salida` int NOT NULL,
  `nivel_combustible_salida` decimal(5,2) NOT NULL,
  `fecha_salida_real` datetime NOT NULL,
  `observaciones_salida` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `kilometraje_regreso` int DEFAULT NULL,
  `nivel_combustible_regreso` decimal(5,2) DEFAULT NULL,
  `fecha_regreso_real` datetime DEFAULT NULL,
  `observaciones_regreso` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `fotos_salida_medidores_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `fotos_salida_observaciones_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `fotos_regreso_medidores_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `fotos_regreso_observaciones_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `historial_uso_vehiculos`
--

INSERT INTO `historial_uso_vehiculos` (`id`, `solicitud_id`, `vehiculo_id`, `usuario_id`, `kilometraje_salida`, `nivel_combustible_salida`, `fecha_salida_real`, `observaciones_salida`, `kilometraje_regreso`, `nivel_combustible_regreso`, `fecha_regreso_real`, `observaciones_regreso`, `fotos_salida_medidores_url`, `fotos_salida_observaciones_url`, `fotos_regreso_medidores_url`, `fotos_regreso_observaciones_url`) VALUES
(1, 2, 1, 1, 25, 50.00, '2025-06-27 21:21:49', 'algo', 30, 25.00, '2025-06-27 21:22:51', 'eddasda', '[\"\\/flotilla\\/storage\\/uploads\\/vehiculo_evidencias\\/evidencia_medidores_685f0b6dd24b9.png\",\"\\/flotilla\\/storage\\/uploads\\/vehiculo_evidencias\\/evidencia_medidores_685f0b6dd25f4.png\"]', '[\"\\/flotilla\\/storage\\/uploads\\/vehiculo_evidencias\\/evidencia_observaciones_685f0b6dd26e5.jpg\",\"\\/flotilla\\/storage\\/uploads\\/vehiculo_evidencias\\/evidencia_observaciones_685f0b6dd28a4.png\"]', '[\"\\/flotilla\\/storage\\/uploads\\/vehiculo_evidencias\\/evidencia_medidores_685f0babdd2bd.jpg\",\"\\/flotilla\\/storage\\/uploads\\/vehiculo_evidencias\\/evidencia_medidores_685f0babdd431.jpg\"]', '[\"\\/flotilla\\/storage\\/uploads\\/vehiculo_evidencias\\/evidencia_observaciones_685f0babdd6b2.jpg\"]');

-- --------------------------------------------------------

--
-- Table structure for table `mantenimientos`
--

CREATE TABLE `mantenimientos` (
  `id` int NOT NULL,
  `vehiculo_id` int NOT NULL,
  `tipo_mantenimiento` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha_mantenimiento` datetime NOT NULL,
  `kilometraje_mantenimiento` int DEFAULT NULL,
  `costo` decimal(10,2) DEFAULT NULL,
  `taller` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observaciones` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `proximo_mantenimiento_km` int DEFAULT NULL,
  `proximo_mantenimiento_fecha` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `solicitudes_vehiculos`
--

CREATE TABLE `solicitudes_vehiculos` (
  `id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `vehiculo_id` int DEFAULT NULL,
  `fecha_salida_solicitada` datetime NOT NULL,
  `fecha_regreso_solicitada` datetime NOT NULL,
  `descripcion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `destino` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `evento` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `estatus_solicitud` enum('pendiente','aprobada','rechazada','en_curso','completada','cancelada') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `fecha_aprobacion` datetime DEFAULT NULL,
  `aprobado_por` int DEFAULT NULL,
  `observaciones_aprobacion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `solicitudes_vehiculos`
--

INSERT INTO `solicitudes_vehiculos` (`id`, `usuario_id`, `vehiculo_id`, `fecha_salida_solicitada`, `fecha_regreso_solicitada`, `descripcion`, `destino`, `evento`, `estatus_solicitud`, `fecha_aprobacion`, `aprobado_por`, `observaciones_aprobacion`, `fecha_creacion`) VALUES
(1, 1, NULL, '2025-06-27 16:00:00', '2025-06-28 00:00:00', 'Prueba', 'Sur oriente', 'Camping', 'cancelada', '2025-07-11 16:12:03', 1, 'Es una prueba', '2025-06-27 20:45:47'),
(2, 1, 1, '2025-06-29 14:46:00', '2025-06-30 14:46:00', 'asdfsadfads', 'Parques', 'Arte en el parque', 'completada', '2025-06-27 20:47:53', 1, '', '2025-06-27 20:47:31'),
(3, 1, 1, '2025-07-01 09:01:00', '2025-07-02 00:00:00', 'gfefsdf', 'fsdfsdf', 'Arte en el parque', 'pendiente', NULL, NULL, NULL, '2025-07-01 09:05:09'),
(4, 1, 1, '2025-07-01 09:12:00', '2025-07-02 00:00:00', 'dfggfdgsdf', 'Suroriente', 'prueba', 'aprobada', '2025-07-01 09:21:42', 1, 'asdasdadsasdsaASDas', '2025-07-01 09:12:16'),
(5, 2, 1, '2025-07-02 08:00:00', '2025-07-03 08:00:00', 'pruebaa', 'prueba', 'Prueba 2', 'aprobada', '2025-07-01 09:29:30', 1, 'Nada en particular | Cambio de usuario: Se incapacito David', '2025-07-01 09:29:14'),
(6, 2, 1, '2025-07-11 22:34:00', '2025-07-11 23:34:00', 'fdsgsdfg', 'dsfgsdg', 'fdfdgfdg', 'pendiente', NULL, NULL, NULL, '2025-07-11 16:35:11');

-- --------------------------------------------------------

--
-- Table structure for table `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int NOT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `correo_electronico` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rol` enum('admin','empleado','flotilla_manager') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'empleado',
  `estatus_cuenta` enum('pendiente_aprobacion','activa','rechazada','inactiva') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente_aprobacion',
  `estatus_usuario` enum('activo','amonestado','suspendido') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activo',
  `google_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
  `ultima_sesion` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `usuarios`
--

INSERT INTO `usuarios` (`id`, `nombre`, `correo_electronico`, `password`, `rol`, `estatus_cuenta`, `estatus_usuario`, `google_id`, `fecha_creacion`, `ultima_sesion`) VALUES
(1, 'David García', 'dgarcia@planjuarez.org', '$2y$10$6wZ4sx/bg37hJrUqs2Xcf.kgDi8uCM7Q5lbtfISsPXqDtQBBKRE7.', 'admin', 'activa', 'activo', NULL, '2025-06-11 19:12:11', '2025-07-11 16:05:32'),
(2, 'Empleado', 'empleado@test.com', '$2y$10$rX6qqeHuYog2Y45BiHzlxODQsMkmxxWoL00vWbu4pGbdpdgBSKdlG', 'empleado', 'activa', 'activo', NULL, '2025-06-11 21:17:40', '2025-07-11 16:35:57'),
(3, 'Lider de flotilla', 'liderflotilla@test.com', '$2y$10$nW5dTKf6aNTW3VamG1Zn0OVTf2aP5Mp1qjOvaafPvIrHheJb2XNZO', 'flotilla_manager', 'activa', 'activo', NULL, '2025-06-11 21:21:21', '2025-06-30 16:51:01'),
(5, 'Fabiola Herrera', 'fherrera@planjuarez.org', '$2y$10$3IUtgxsjaoPfTLvAqrzIN.RgK2pP6vQ5kCx6cJKAljMAfgPrUtWGK', 'admin', 'activa', 'activo', NULL, '2025-06-12 15:46:55', '2025-06-25 22:40:43');

-- --------------------------------------------------------

--
-- Table structure for table `vehiculos`
--

CREATE TABLE `vehiculos` (
  `id` int NOT NULL,
  `marca` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `modelo` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `anio` int NOT NULL,
  `placas` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `vin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_combustible` enum('Gasolina','Diésel','Eléctrico','Híbrido') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kilometraje_actual` int NOT NULL DEFAULT '0',
  `estatus` enum('disponible','en_uso','en_mantenimiento','inactivo') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disponible',
  `ubicacion_actual` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observaciones` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `fecha_registro` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehiculos`
--

INSERT INTO `vehiculos` (`id`, `marca`, `modelo`, `anio`, `placas`, `vin`, `tipo_combustible`, `kilometraje_actual`, `estatus`, `ubicacion_actual`, `observaciones`, `fecha_registro`) VALUES
(1, 'VOLKSWAGEN', 'GOL', 2019, 'Unas', '', 'Gasolina', 30, 'disponible', 'ESTACIONAMIENTO', '', '2025-06-27 20:38:49');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `amonestaciones`
--
ALTER TABLE `amonestaciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `amonestado_por` (`amonestado_por`);

--
-- Indexes for table `documentos_vehiculos`
--
ALTER TABLE `documentos_vehiculos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehiculo_id` (`vehiculo_id`),
  ADD KEY `subido_por` (`subido_por`);

--
-- Indexes for table `historial_uso_vehiculos`
--
ALTER TABLE `historial_uso_vehiculos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `solicitud_id` (`solicitud_id`),
  ADD KEY `vehiculo_id` (`vehiculo_id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indexes for table `mantenimientos`
--
ALTER TABLE `mantenimientos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehiculo_id` (`vehiculo_id`);

--
-- Indexes for table `solicitudes_vehiculos`
--
ALTER TABLE `solicitudes_vehiculos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `vehiculo_id` (`vehiculo_id`),
  ADD KEY `aprobado_por` (`aprobado_por`);

--
-- Indexes for table `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `correo_electronico` (`correo_electronico`),
  ADD UNIQUE KEY `google_id` (`google_id`);

--
-- Indexes for table `vehiculos`
--
ALTER TABLE `vehiculos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `placas` (`placas`),
  ADD UNIQUE KEY `vin` (`vin`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `amonestaciones`
--
ALTER TABLE `amonestaciones`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `documentos_vehiculos`
--
ALTER TABLE `documentos_vehiculos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `historial_uso_vehiculos`
--
ALTER TABLE `historial_uso_vehiculos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `mantenimientos`
--
ALTER TABLE `mantenimientos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `solicitudes_vehiculos`
--
ALTER TABLE `solicitudes_vehiculos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `vehiculos`
--
ALTER TABLE `vehiculos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `amonestaciones`
--
ALTER TABLE `amonestaciones`
  ADD CONSTRAINT `amonestaciones_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `amonestaciones_ibfk_2` FOREIGN KEY (`amonestado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `documentos_vehiculos`
--
ALTER TABLE `documentos_vehiculos`
  ADD CONSTRAINT `documentos_vehiculos_ibfk_1` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `documentos_vehiculos_ibfk_2` FOREIGN KEY (`subido_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `historial_uso_vehiculos`
--
ALTER TABLE `historial_uso_vehiculos`
  ADD CONSTRAINT `historial_uso_vehiculos_ibfk_1` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitudes_vehiculos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `historial_uso_vehiculos_ibfk_2` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `historial_uso_vehiculos_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mantenimientos`
--
ALTER TABLE `mantenimientos`
  ADD CONSTRAINT `mantenimientos_ibfk_1` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `solicitudes_vehiculos`
--
ALTER TABLE `solicitudes_vehiculos`
  ADD CONSTRAINT `solicitudes_vehiculos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `solicitudes_vehiculos_ibfk_2` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `solicitudes_vehiculos_ibfk_3` FOREIGN KEY (`aprobado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
