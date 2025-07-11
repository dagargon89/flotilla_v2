<?php
// app/includes/navbar.php - CÓDIGO ACTUALIZADO CON LÓGICA PARA USUARIOS SUSPENDIDOS

// Asegúrate de que estas variables estén definidas antes de incluir este archivo
// En cada página, antes de require_once '../app/includes/navbar.php', deberías tener:
// $nombre_usuario_sesion = $_SESSION['user_name'] ?? 'Usuario';
// $rol_usuario_sesion = $_SESSION['user_role'] ?? 'empleado';
// $current_user_estatus_usuario = $_SESSION['user_estatus_usuario'] ?? 'activo'; // Necesario para la lógica aquí
?>
<nav class="bg-darkpurple shadow-lg">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <!-- Logo y marca -->
            <div class="flex items-center">
                <a href="dashboard.php" class="flex-shrink-0 flex items-center">
                    <span class="text-white text-xl font-bold">Flotilla Interna</span>
                </a>
            </div>

            <!-- Menú principal - Desktop -->
            <div class="hidden md:flex items-center space-x-8">
                <?php if (!isset($current_user_estatus_usuario) || $current_user_estatus_usuario !== 'suspendido'): ?>
                    <a href="dashboard.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'bg-mountbatten text-white' : 'text-gray-300 hover:text-white hover:bg-mountbatten'; ?> px-3 py-2 rounded-md text-sm font-medium transition-colors duration-200">
                        Dashboard
                    </a>
                    <a href="solicitar_vehiculo.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'solicitar_vehiculo.php') ? 'bg-mountbatten text-white' : 'text-gray-300 hover:text-white hover:bg-mountbatten'; ?> px-3 py-2 rounded-md text-sm font-medium transition-colors duration-200">
                        Solicitar Vehículo
                    </a>
                    <a href="mis_solicitudes.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'mis_solicitudes.php') ? 'bg-mountbatten text-white' : 'text-gray-300 hover:text-white hover:bg-mountbatten'; ?> px-3 py-2 rounded-md text-sm font-medium transition-colors duration-200">
                        Mis Solicitudes
                    </a>

                    <?php
                    $admin_only_pages = ['gestion_vehiculos.php', 'gestion_mantenimientos.php', 'gestion_documentos.php', 'gestion_usuarios.php'];
                    $manager_admin_pages = ['gestion_solicitudes.php', 'reportes.php'];

                    $is_admin_page_active = in_array(basename($_SERVER['PHP_SELF']), $admin_only_pages);
                    $is_manager_admin_page_active = in_array(basename($_SERVER['PHP_SELF']), $manager_admin_pages);
                    ?>

                    <?php if (isset($rol_usuario_sesion) && ($rol_usuario_sesion === 'flotilla_manager' || $rol_usuario_sesion === 'admin')): ?>
                        <!-- Dropdown Administración - Desktop -->
                        <div class="relative group">
                            <button class="<?php echo ($is_manager_admin_page_active || $is_admin_page_active) ? 'bg-mountbatten text-white' : 'text-gray-300 hover:text-white hover:bg-mountbatten'; ?> px-3 py-2 rounded-md text-sm font-medium transition-colors duration-200 flex items-center">
                                Administración
                                <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>
                            <div class="absolute left-0 mt-2 w-64 bg-white rounded-md shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50">
                                <div class="py-1">
                                    <?php if ($rol_usuario_sesion === 'flotilla_manager' || $rol_usuario_sesion === 'admin'): ?>
                                        <a href="gestion_solicitudes.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'gestion_solicitudes.php') ? 'bg-cambridge1 text-white' : 'text-gray-700 hover:bg-cambridge2 hover:text-white'; ?> block px-4 py-2 text-sm transition-colors duration-200">
                                            Gestión de Solicitudes
                                        </a>
                                        <a href="reportes.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reportes.php') ? 'bg-cambridge1 text-white' : 'text-gray-700 hover:bg-cambridge2 hover:text-white'; ?> block px-4 py-2 text-sm transition-colors duration-200">
                                            Reportes y Estadísticas
                                        </a>
                                    <?php endif; ?>

                                    <?php if (isset($rol_usuario_sesion) && $rol_usuario_sesion === 'admin'): ?>
                                        <div class="border-t border-gray-200 my-1"></div>
                                        <a href="gestion_vehiculos.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'gestion_vehiculos.php') ? 'bg-cambridge1 text-white' : 'text-gray-700 hover:bg-cambridge2 hover:text-white'; ?> block px-4 py-2 text-sm transition-colors duration-200">
                                            Gestión de Vehículos
                                        </a>
                                        <a href="gestion_mantenimientos.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'gestion_mantenimientos.php') ? 'bg-cambridge1 text-white' : 'text-gray-700 hover:bg-cambridge2 hover:text-white'; ?> block px-4 py-2 text-sm transition-colors duration-200">
                                            Gestión de Mantenimientos
                                        </a>
                                        <a href="gestion_documentos.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'gestion_documentos.php') ? 'bg-cambridge1 text-white' : 'text-gray-700 hover:bg-cambridge2 hover:text-white'; ?> block px-4 py-2 text-sm transition-colors duration-200">
                                            Gestión de Documentos
                                        </a>
                                        <div class="border-t border-gray-200 my-1"></div>
                                        <a href="gestion_usuarios.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'gestion_usuarios.php') ? 'bg-cambridge1 text-white' : 'text-gray-700 hover:bg-cambridge2 hover:text-white'; ?> block px-4 py-2 text-sm transition-colors duration-200">
                                            Gestión de Usuarios
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Menú de usuario - Desktop -->
            <div class="hidden md:flex items-center">
                <div class="relative group">
                    <button class="text-gray-300 hover:text-white hover:bg-mountbatten px-3 py-2 rounded-md text-sm font-medium transition-colors duration-200 flex items-center">
                        Hola, <?php echo htmlspecialchars($nombre_usuario_sesion ?? 'Usuario'); ?>
                        (<?php echo htmlspecialchars($rol_usuario_sesion ?? 'empleado'); ?>)
                        <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50">
                        <div class="py-1">
                            <?php if (isset($current_user_estatus_usuario) && $current_user_estatus_usuario === 'suspendido'): ?>
                                <a href="suspended.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'suspended.php') ? 'bg-cambridge1 text-white' : 'text-gray-700 hover:bg-cambridge2 hover:text-white'; ?> block px-4 py-2 text-sm transition-colors duration-200">
                                    Mi Suspensión
                                </a>
                                <div class="border-t border-gray-200 my-1"></div>
                            <?php else: ?>
                                <a href="mis_solicitudes.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'mis_solicitudes.php') ? 'bg-cambridge1 text-white' : 'text-gray-700 hover:bg-cambridge2 hover:text-white'; ?> block px-4 py-2 text-sm transition-colors duration-200">
                                    Mis Solicitudes
                                </a>
                                <a href="mi_perfil.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'mi_perfil.php') ? 'bg-cambridge1 text-white' : 'text-gray-700 hover:bg-cambridge2 hover:text-white'; ?> block px-4 py-2 text-sm transition-colors duration-200">
                                    Mi Perfil
                                </a>
                                <div class="border-t border-gray-200 my-1"></div>
                            <?php endif; ?>
                            <a href="logout.php" class="text-gray-700 hover:bg-red-100 hover:text-red-700 block px-4 py-2 text-sm transition-colors duration-200">
                                Cerrar Sesión
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Botón móvil -->
            <div class="md:hidden flex items-center">
                <button id="mobile-menu-button" class="text-gray-300 hover:text-white hover:bg-mountbatten p-2 rounded-md transition-colors duration-200">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Menú móvil -->
    <div id="mobile-menu" class="md:hidden hidden bg-darkpurple">
        <div class="px-2 pt-2 pb-3 space-y-1">
            <?php if (!isset($current_user_estatus_usuario) || $current_user_estatus_usuario !== 'suspendido'): ?>
                <a href="dashboard.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'bg-mountbatten text-white' : 'text-gray-300 hover:text-white hover:bg-mountbatten'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                    Dashboard
                </a>
                <a href="solicitar_vehiculo.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'solicitar_vehiculo.php') ? 'bg-mountbatten text-white' : 'text-gray-300 hover:text-white hover:bg-mountbatten'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                    Solicitar Vehículo
                </a>
                <a href="mis_solicitudes.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'mis_solicitudes.php') ? 'bg-mountbatten text-white' : 'text-gray-300 hover:text-white hover:bg-mountbatten'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                    Mis Solicitudes
                </a>

                <?php if (isset($rol_usuario_sesion) && ($rol_usuario_sesion === 'flotilla_manager' || $rol_usuario_sesion === 'admin')): ?>
                    <div class="border-t border-gray-600 my-2"></div>
                    <div class="text-gray-400 px-3 py-2 text-sm font-medium">Administración</div>

                    <?php if ($rol_usuario_sesion === 'flotilla_manager' || $rol_usuario_sesion === 'admin'): ?>
                        <a href="gestion_solicitudes.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'gestion_solicitudes.php') ? 'bg-cambridge1 text-white' : 'text-gray-300 hover:text-white hover:bg-cambridge2'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                            Gestión de Solicitudes
                        </a>
                        <a href="reportes.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reportes.php') ? 'bg-cambridge1 text-white' : 'text-gray-300 hover:text-white hover:bg-cambridge2'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                            Reportes y Estadísticas
                        </a>
                    <?php endif; ?>

                    <?php if (isset($rol_usuario_sesion) && $rol_usuario_sesion === 'admin'): ?>
                        <a href="gestion_vehiculos.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'gestion_vehiculos.php') ? 'bg-cambridge1 text-white' : 'text-gray-300 hover:text-white hover:bg-cambridge2'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                            Gestión de Vehículos
                        </a>
                        <a href="gestion_mantenimientos.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'gestion_mantenimientos.php') ? 'bg-cambridge1 text-white' : 'text-gray-300 hover:text-white hover:bg-cambridge2'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                            Gestión de Mantenimientos
                        </a>
                        <a href="gestion_documentos.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'gestion_documentos.php') ? 'bg-cambridge1 text-white' : 'text-gray-300 hover:text-white hover:bg-cambridge2'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                            Gestión de Documentos
                        </a>
                        <a href="gestion_usuarios.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'gestion_usuarios.php') ? 'bg-cambridge1 text-white' : 'text-gray-300 hover:text-white hover:bg-cambridge2'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                            Gestión de Usuarios
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <div class="border-t border-gray-600 my-2"></div>
            <div class="text-gray-400 px-3 py-2 text-sm font-medium">
                Hola, <?php echo htmlspecialchars($nombre_usuario_sesion ?? 'Usuario'); ?>
                (<?php echo htmlspecialchars($rol_usuario_sesion ?? 'empleado'); ?>)
            </div>

            <?php if (isset($current_user_estatus_usuario) && $current_user_estatus_usuario === 'suspendido'): ?>
                <a href="suspended.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'suspended.php') ? 'bg-cambridge1 text-white' : 'text-gray-300 hover:text-white hover:bg-cambridge2'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                    Mi Suspensión
                </a>
            <?php else: ?>
                <a href="mis_solicitudes.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'mis_solicitudes.php') ? 'bg-cambridge1 text-white' : 'text-gray-300 hover:text-white hover:bg-cambridge2'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                    Mis Solicitudes
                </a>
                <a href="mi_perfil.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'mi_perfil.php') ? 'bg-cambridge1 text-white' : 'text-gray-300 hover:text-white hover:bg-cambridge2'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                    Mi Perfil
                </a>
            <?php endif; ?>

            <a href="logout.php" class="text-gray-300 hover:text-white hover:bg-red-600 block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                Cerrar Sesión
            </a>
        </div>
    </div>
</nav>

<script>
    // JavaScript para el menú móvil
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');

        if (mobileMenuButton && mobileMenu) {
            mobileMenuButton.addEventListener('click', function() {
                mobileMenu.classList.toggle('hidden');
            });
        }
    });
</script>