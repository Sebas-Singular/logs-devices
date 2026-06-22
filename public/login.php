<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\Support\Bootstrap;
use App\Viewer\ViewerAuth;
use App\Viewer\ViewerSession;

require_once __DIR__ . '/../vendor/autoload.php';

Bootstrap::init();
SecurityHeaders::applyViewer();

if (!ViewerAuth::isEnabled()) {
    header('Location: /');
    exit;
}

ViewerSession::start();

if (ViewerSession::isAuthenticated()) {
    $redirect = sanitizeRedirect((string) ($_GET['redirect'] ?? '/'));
    header('Location: ' . $redirect);
    exit;
}

$error  = null;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $submittedCsrf = trim((string) ($_POST['csrf_token'] ?? ''));
    $username      = trim((string) ($_POST['username'] ?? ''));
    $password      = (string) ($_POST['password'] ?? '');

    if (!ViewerSession::validateCsrf($submittedCsrf)) {
        $error = 'Solicitud no válida. Recarga la página e inténtalo de nuevo.';
    } elseif (ViewerAuth::checkCredentials($username, $password)) {
        ViewerSession::login();
        $redirect = sanitizeRedirect((string) ($_POST['redirect'] ?? '/'));
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}

$csrfToken  = ViewerSession::csrfToken();
$redirectTo = sanitizeRedirect((string) ($_GET['redirect'] ?? '/'));

function sanitizeRedirect(string $url): string
{
    if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
        return '/';
    }
    return $url;
}

$sessionMinutes = (int) round(ViewerSession::lifetime() / 60);

?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>logs-devices · Acceso</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-900 flex items-center justify-center px-4">

    <div class="w-full max-w-sm">

        <div class="mb-8 text-center">
            <p class="text-xs font-mono tracking-widest text-slate-500 uppercase mb-2">
                Singular Things
            </p>
            <h1 class="text-2xl font-bold text-white">logs-devices</h1>
            <p class="mt-1 text-sm text-slate-400">Visor de logs IoT</p>
        </div>

        <?php if ($error !== null): ?>
            <div class="mb-4 rounded-lg border border-red-700 bg-red-900/30 px-4 py-3 text-sm text-red-300">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form
            method="POST"
            action="/login.php?redirect=<?= urlencode($redirectTo) ?>"
            class="rounded-xl border border-slate-700 bg-slate-800 px-6 py-8 shadow-xl space-y-5">
            <input type="hidden" name="csrf_token"
                value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="redirect"
                value="<?= htmlspecialchars($redirectTo, ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <label for="username" class="block text-xs font-medium text-slate-400 mb-1.5">
                    Usuario
                </label>
                <input
                    id="username"
                    name="username"
                    type="text"
                    autocomplete="username"
                    required
                    autofocus
                    class="w-full rounded-lg border border-slate-600 bg-slate-700 text-white
                       px-3 py-2 text-sm placeholder-slate-500
                       focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent
                       transition-colors">
            </div>

            <div>
                <label for="password" class="block text-xs font-medium text-slate-400 mb-1.5">
                    Contraseña
                </label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="w-full rounded-lg border border-slate-600 bg-slate-700 text-white
                       px-3 py-2 text-sm
                       focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent
                       transition-colors">
            </div>

            <button
                type="submit"
                class="w-full rounded-lg bg-blue-600 hover:bg-blue-500 active:bg-blue-700
                   text-white font-medium text-sm py-2.5 px-4
                   transition-colors duration-150
                   focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2
                   focus:ring-offset-slate-800">
                Acceder
            </button>
        </form>

        <p class="mt-4 text-center text-xs text-slate-600">
            La sesión expira tras <?= $sessionMinutes ?> minutos de inactividad.
        </p>

    </div>

</body>

</html>