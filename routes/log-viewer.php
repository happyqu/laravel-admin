<?php

use Encore\Admin\LogViewer\LogController;

$router->get('logs', [LogController::class, 'index'])->name('log-viewer-index');
$router->delete('logs-deleteall', [LogController::class, 'deleteAll'])->name('log-viewer-deleteall');
$router->get('logs/{file}', [LogController::class, 'index'])->name('log-viewer-file');
$router->delete('logs/{file}/delete', [LogController::class, 'delete'])->name('log-viewer-delete');
$router->get('logs/{file}/tail', [LogController::class, 'tail'])->name('log-viewer-tail');
