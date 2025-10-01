<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // Importamos el helper de String de Laravel
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use ZipArchive;

class PdfController extends Controller
{
    public function merge(Request $request)
    {
        $validator = validator($request->all(), [
            'pdfs'   => 'required|array',
            'pdfs.*' => 'required|file|mimes:pdf|max:10240',
            'order'   => 'required|array',
            'order.*' => 'required|string',
            'output_name' => 'nullable|string|max:100', // NUEVO: Validación para el nombre de salida
        ]);

         if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-merger-' . uniqid();
        File::makeDirectory($tempDir);

        try {
            $order = $request->input('order');
            $tempPaths = [];
            
            // Gracias a la corrección del frontend, getClientOriginalName() ahora nos dará el nombre lógico (ej: '10-1.pdf')
            foreach ($request->file('pdfs') as $file) {
                $originalName = $file->getClientOriginalName();
                $nameWithoutExtension = pathinfo($originalName, PATHINFO_FILENAME);
                $file->move($tempDir, $originalName);
                $fullPath = $tempDir . DIRECTORY_SEPARATOR . $originalName;
                $tempPaths[$nameWithoutExtension] = $fullPath;
            }

            $orderedPathsForCommand = [];
            $unmatchedNames = [];

            foreach ($order as $filenameInOrder) {
                if (isset($tempPaths[$filenameInOrder])) {
                    $orderedPathsForCommand[] = $tempPaths[$filenameInOrder];
                } else {
                    $unmatchedNames[] = $filenameInOrder;
                }
            }

            if (!empty($unmatchedNames)) {
                $errorMsg = 'El orden contiene nombres de archivo que no fueron subidos: ' . implode(', ', $unmatchedNames);
                throw new \Exception($errorMsg);
            }

            if (empty($orderedPathsForCommand)) {
                throw new \Exception('No se encontraron archivos PDF válidos para unir.');
            }

            $outputPath = $tempDir . DIRECTORY_SEPARATOR . 'merged.pdf';
            $pdftkPath = 'C:\\Program Files (x86)\\PDFtk Server\\bin\\pdftk.exe';
            
            $command = array_merge([$pdftkPath], $orderedPathsForCommand, ['cat', 'output', $outputPath]);

            $process = new Process($command);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $outputContent = File::get($outputPath);

            // NUEVO: Usar el nombre de archivo proporcionado por el usuario
            $outputName = $request->input('output_name', 'documento-unido');
            // Limpiamos el nombre para que sea un nombre de archivo seguro
            $sanitizedName = Str::slug($outputName, '-');
            $finalFilename = $sanitizedName . '.pdf';


            return response($outputContent, 200, [
                'Content-Type' => 'application/pdf',
                // Usamos el nombre de archivo final sanitizado
                'Content-Disposition' => 'attachment; filename="' . $finalFilename . '"',
            ]);

        } catch (\Exception $e) {
            Log::error('Error al unir PDFs: ' . $e->getMessage());
            if (isset($process)) {
                Log::error('pdftk error output: ' . $process->getErrorOutput());
            }
            return response()->json(['error' => $e->getMessage()], 422);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }


    public function mergeAndZipByGroup(Request $request)
    {
        // CONFIGURACIÓN PARA MUCHOS ARCHIVOS
        ini_set('max_file_uploads', 100);
        ini_set('max_input_vars', 5000);
        ini_set('post_max_size', '2G');
        ini_set('upload_max_filesize', '100M');
        ini_set('memory_limit', '4G');
        ini_set('max_execution_time', 1800);
        
        $validator = validator($request->all(), [
            'pdfs' => 'required|array',
            'pdfs.*' => 'required|file|mimes:pdf|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $mainTempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-grouper-' . uniqid();
        $uploadsDir = $mainTempDir . DIRECTORY_SEPARATOR . 'uploads';
        $mergedDir = $mainTempDir . DIRECTORY_SEPARATOR . 'merged';
        
        File::makeDirectory($mainTempDir, 0755, true, true);
        File::makeDirectory($uploadsDir, 0755, true, true);
        File::makeDirectory($mergedDir, 0755, true, true);

        try {
            $files = $request->file('pdfs');
            
            Log::info('=== PROCESANDO ARCHIVOS ===');
            Log::info('Total archivos recibidos: ' . count($files));
            
            $groupedFiles = [];

            foreach ($files as $index => $file) {
                $originalName = $file->getClientOriginalName();
                
                $targetPath = $uploadsDir . DIRECTORY_SEPARATOR . $originalName;
                if (file_exists($targetPath)) {
                    $counter = 1;
                    $pathInfo = pathinfo($originalName);
                    $baseName = $pathInfo['filename'];
                    $extension = $pathInfo['extension'];
                    
                    do {
                        $newName = "{$baseName}_conflict_{$counter}.{$extension}";
                        $targetPath = $uploadsDir . DIRECTORY_SEPARATOR . $newName;
                        $counter++;
                    } while (file_exists($targetPath));
                    
                    $file->move($uploadsDir, $newName);
                    $originalName = $newName;
                } else {
                    $file->move($uploadsDir, $originalName);
                }
                
                $baseName = $this->extractNumericPrefix($originalName);
                
                if (!isset($groupedFiles[$baseName])) {
                    $groupedFiles[$baseName] = [];
                }
                $groupedFiles[$baseName][] = $uploadsDir . DIRECTORY_SEPARATOR . $originalName;
            }

            Log::info('Grupos creados: ' . count($groupedFiles));
            foreach ($groupedFiles as $groupName => $filePaths) {
                Log::info("Grupo '{$groupName}': " . count($filePaths) . " archivos");
            }

            $mergedPdfPaths = [];
            $pdftkPath = 'C:\\Program Files (x86)\\PDFtk Server\\bin\\pdftk.exe';

            foreach ($groupedFiles as $groupName => $filePaths) {
                if (count($filePaths) > 0) {
                    $outputPdfPath = $mergedDir . DIRECTORY_SEPARATOR . $groupName . '.pdf';
                    
                    sort($filePaths, SORT_NATURAL);
                    
                    $command = array_merge([$pdftkPath], $filePaths, ['cat', 'output', $outputPdfPath]);
                    $process = new Process($command);
                    $process->setTimeout(600);
                    $process->run();

                    if (!$process->isSuccessful()) {
                        Log::error("Error en grupo '{$groupName}': " . $process->getErrorOutput());
                        throw new ProcessFailedException($process);
                    }
                    
                    if (file_exists($outputPdfPath)) {
                        $mergedPdfPaths[] = $outputPdfPath;
                        Log::info("PDF generado: " . basename($outputPdfPath));
                    }
                }
            }
            
            if (empty($mergedPdfPaths)) {
                throw new \Exception('No se pudo generar ningún PDF combinado.');
            }

            $zipPath = $mainTempDir . DIRECTORY_SEPARATOR . 'documentos_unidos.zip';
            $zip = new ZipArchive();

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new \Exception("No se pudo crear el archivo ZIP.");
            }

            foreach ($mergedPdfPaths as $pdfPath) {
                if (file_exists($pdfPath)) {
                    $zip->addFile($pdfPath, basename($pdfPath));
                    Log::info("Añadido al ZIP: " . basename($pdfPath));
                }
            }
            $zip->close();
            
            Log::info('ZIP creado exitosamente: ' . filesize($zipPath) . ' bytes');
            
            File::deleteDirectory($uploadsDir);
            File::deleteDirectory($mergedDir);
            
            return response()->download($zipPath, 'documentos_unidos.zip')->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Error en mergeAndZipByGroup: ' . $e->getMessage());
            
            if (File::exists($mainTempDir)) {
                File::deleteDirectory($mainTempDir);
            }
            
            return response()->json(['error' => 'Error en el servidor: ' . $e->getMessage()], 500);
        }
    }
    
    private function extractNumericPrefix($filename) {
        // Remover extensión si existe
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        
        // Limpiar caracteres especiales comunes
        $cleaned = str_replace(['(', ')', ' - ', '_'], ' ', $nameWithoutExt);
        
        // Patrón regex para capturar los primeros números consecutivos
        if (preg_match('/^(\d+)/', $cleaned, $matches)) {
            return $matches[1];
        }
        
        // Si no encuentra números al inicio, usar la primera palabra alfanumérica
        if (preg_match('/^([a-zA-Z0-9]+)/', $cleaned, $matches)) {
            return $matches[1];
        }
        
        // Fallback: devolver nombre limpio sin caracteres especiales
        return preg_replace('/[^a-zA-Z0-9]/', '', $nameWithoutExt);
    }
}
