<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Rotas do módulo de mentira usado por ModuleSystemTest. Existe só para provar
// que o ModuleServiceProvider carrega routes/web.php sozinho.

Route::get('example-fixture', fn (): string => 'ok')->name('example.fixture');
