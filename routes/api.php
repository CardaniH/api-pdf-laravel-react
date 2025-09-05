<?php
// routes/api.php
use App\Http\Controllers\PdfController;
use Illuminate\Support\Facades\Route;

Route::post('/merge-pdfs', [PdfController::class, 'merge']);