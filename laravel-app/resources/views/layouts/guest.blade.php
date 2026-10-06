<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head', ['title' => trim($__env->yieldContent('title')) ?: 'Masuk'])
</head>
<body class="min-h-screen">
    @yield('content')
</body>
</html>
