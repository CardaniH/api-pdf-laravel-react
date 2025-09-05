<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia; // Asegúrate de importar Inertia

// Cuando alguien visite la URL raíz '/', Laravel renderizará el componente de React 'Uploader'.
Route::get('/', function () {
    return Inertia::render('Uploader');
});

// Puedes borrar o comentar las otras rutas como /dashboard si no las necesitas.
// require __DIR__.'/auth.php';