<!DOCTYPE html>
<html lang="es">
{{--
    Layout del sistema de gestión: navbar, contenido y pie.

    Reemplaza al LayoutComponent de Angular. Todas las vistas autenticadas lo
    extienden. El login usa layouts/auth.blade.php, que ocupa la pantalla
    completa y no tiene navbar.

    Incluye:
      partials.navbar    menú, construido con @can sobre permisos
      partials.alertas   mensajes de éxito y de error de la sesión
      partials.footer    datos de la cursada
--}}
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'CompuStack'))</title>
    <link rel="icon" href="{{ asset('assets/isotipo.svg') }}" type="image/svg+xml">

    <!-- Tipografía Nunito -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet">

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>

<body class="d-flex flex-column min-vh-100">

    @include('partials.navbar')

    <main class="container my-4 my-md-5 flex-grow-1">
        @include('partials.alertas')

        @yield('content')
    </main>

    @include('partials.footer')

</body>

</html>
