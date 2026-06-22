<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\Support\Bootstrap;
use App\Viewer\ViewerSession;

require_once __DIR__ . '/../vendor/autoload.php';

Bootstrap::init();
SecurityHeaders::applyViewer();

ViewerSession::start();
ViewerSession::destroy();

header('Location: /login.php');
exit;
