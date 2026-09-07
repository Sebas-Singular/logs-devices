<?php

declare(strict_types=1);

/**
 * Cabecera común de todas las vistas del visor.
 *
 * Antes cada página repetía su propio <head> con el CDN de Tailwind, así que
 * cualquier ajuste de estilo había que replicarlo en siete ficheros. Aquí vive
 * la única definición de la paleta corporativa.
 *
 * Define $pageTitle antes de incluir este fichero.
 *
 * Paleta de marca (tomada de Logo-singular.svg):
 *   brand  #E40D7E  magenta corporativo — acento, enlaces, estado activo
 *   ink    #1E1E1C  negro corporativo   — texto y neutros
 *
 * El magenta se reserva para lo interactivo y de marca. Los estados del sistema
 * usan su propia escala semántica para que "es un enlace" y "algo va mal" no
 * compartan color.
 */

$pageTitle = isset($pageTitle) ? (string) $pageTitle : 'Panel';
?>
<meta charset="utf-8">
<title>logs-devices · <?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    // Magenta corporativo. Contraste sobre blanco: 500 = 4.51:1,
                    // 600 = 5.81:1, 700 = 7.87:1 (AA para texto normal).
                    brand: {
                        50: '#FEF5FA',
                        100: '#FCE7F2',
                        200: '#F9CAE3',
                        300: '#F499C9',
                        400: '#EC56A5',
                        500: '#E40D7E',
                        600: '#C40B6C',
                        700: '#A00958',
                        800: '#7B0744',
                        900: '#5B0532',
                        950: '#3B0321',
                    },
                    // Neutro derivado del negro corporativo. Sustituye a la escala
                    // slate de Tailwind, que tira a azul y no es de marca.
                    ink: {
                        50: '#F9F9F9',
                        100: '#F3F3F3',
                        200: '#E2E2E1',
                        300: '#C4C4C4',
                        400: '#9A9A99',
                        500: '#787877',
                        600: '#585857',
                        700: '#424240',
                        800: '#2E2E2C',
                        900: '#1E1E1C',
                        950: '#141412',
                    },
                },
                fontFamily: {
                    sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Helvetica Neue', 'Arial', 'sans-serif'],
                },
            },
        },
    };
</script>

<style>
    [x-cloak] {
        display: none !important;
    }

    /* El foco del teclado se marca con el magenta de marca en toda la app. */
    :where(a, button, input, select, summary):focus-visible {
        outline: 2px solid #E40D7E;
        outline-offset: 2px;
        border-radius: 4px;
    }

</style>
