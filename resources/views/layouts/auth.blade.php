<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name', 'CompuStack'))</title>
    <link rel="icon" href="{{ asset('assets/isotipo.svg') }}" type="image/svg+xml">

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>

<body class="pantalla-auth d-flex justify-content-center align-items-center">

    @yield('content')

</body>

</html>
