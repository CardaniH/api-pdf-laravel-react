<?php
// routes/api.php
use App\Http\Controllers\PdfController;
use Illuminate\Support\Facades\Route;

// RUTAS CORREGIDAS para coincidir con el frontend
Route::post('/pdfs/merge', [PdfController::class, 'merge']);
Route::post('/pdfs/merge-by-group', [PdfController::class, 'mergeAndZipByGroup']);
