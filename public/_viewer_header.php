<?php

declare(strict_types=1);

$activePage = isset($activePage) ? (string) $activePage : '';

$navItems = [
    [
        'key' => 'dashboard',
        'href' => '/',
        'label' => 'Panel',
    ],
    [
        'key' => 'devices',
        'href' => '/devices.php',
        'label' => 'Dispositivos',
    ],
    [
        'key' => 'events',
        'href' => '/events.php',
        'label' => 'Eventos',
    ],
    [
        'key' => 'ingests',
        'href' => '/ingests.php',
        'label' => 'Ingestas',
    ],
];
?>
<header class="border-b border-ink-200 bg-white">
    <div class="mx-auto grid max-w-7xl grid-cols-[auto_1fr_auto] items-center gap-6 px-6 py-5">
        <a href="/" class="flex min-w-0 flex-col items-start gap-1">
            <img src="/Logo-singular.svg" alt="Singularthings" class="h-10 w-auto shrink-0">
            <div class="text-sm text-ink-500">Logs Devices</div>
        </a>

        <nav class="flex items-center justify-center gap-3 text-sm">
            <?php foreach ($navItems as $item): ?>
                <?php $isActive = $activePage === $item['key']; ?>
                <a
                    href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    class="<?= $isActive ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' ?> rounded-lg px-4 py-2 font-medium whitespace-nowrap">
                    <?= htmlspecialchars((string) $item['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="flex justify-end">
            <a href="/logout.php"
                class="rounded-lg px-3 py-2 text-sm font-medium text-ink-500 hover:bg-ink-100 hover:text-ink-900 whitespace-nowrap"
                title="Cerrar sesión">
                Salir
            </a>
        </div>
    </div>
</header>
