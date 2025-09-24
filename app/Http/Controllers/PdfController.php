<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Str;
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
            'output_name' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-merger-' . uniqid();
        File::makeDirectory($tempDir);

        try {
            $order = $request->input('order');
            $tempPaths = [];
            
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
            $outputName = $request->input('output_name', 'documento-unido');
            $sanitizedName = Str::slug($outputName, '-');
            $finalFilename = $sanitizedName . '.pdf';

            return response($outputContent, 200, [
                'Content-Type' => 'application/pdf',
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
        
        // 🔍 CHECKPOINT 1: Archivos recibidos
        Log::info('=== CHECKPOINT 1: ARCHIVOS RECIBIDOS ===');
        Log::info('Total archivos recibidos: ' . count($files));
        
        $groupedFiles = [];

        // 1. Guardar y agrupar archivos
        foreach ($files as $index => $file) {
            $originalName = $file->getClientOriginalName();
            
            Log::info("Archivo {$index}: {$originalName}");
            
            $file->move($uploadsDir, $originalName);
            
            $nameWithoutExtension = pathinfo($originalName, PATHINFO_FILENAME);
            $baseName = explode('-', $nameWithoutExtension)[0];
            
            if (!isset($groupedFiles[$baseName])) {
                $groupedFiles[$baseName] = [];
            }
            $groupedFiles[$baseName][] = $uploadsDir . DIRECTORY_SEPARATOR . $originalName;
        }

        // 🔍 CHECKPOINT 2: Agrupación
        Log::info('=== CHECKPOINT 2: AGRUPACIÓN COMPLETADA ===');
        foreach ($groupedFiles as $groupName => $filePaths) {
            Log::info("Grupo '{$groupName}': " . count($filePaths) . " archivos");
            foreach ($filePaths as $index => $path) {
                Log::info("  [{$index}] " . basename($path));
            }
        }

        $mergedPdfPaths = [];
        $pdftkPath = 'C:\\Program Files (x86)\\PDFtk Server\\bin\\pdftk.exe';

        // 🔍 CHECKPOINT 3: Procesamiento por grupos
        Log::info('=== CHECKPOINT 3: PROCESANDO GRUPOS ===');
        
        foreach ($groupedFiles as $groupName => $filePaths) {
            Log::info("Iniciando procesamiento grupo '{$groupName}' con " . count($filePaths) . " archivos");
            
            if (count($filePaths) > 0) {
                $outputPdfPath = $mergedDir . DIRECTORY_SEPARATOR . $groupName . '.pdf';
                
                sort($filePaths, SORT_NATURAL);
                
                // 🔍 MOSTRAR COMANDO COMPLETO
                $command = array_merge([$pdftkPath], $filePaths, ['cat', 'output', $outputPdfPath]);
                Log::info("Comando PDFTK para grupo '{$groupName}':");
                Log::info("Número de archivos en comando: " . (count($command) - 4)); // -4 por pdftk, cat, output, outfile
                Log::info("Comando: " . implode(' ', array_map(function($item) {
                    return '"' . $item . '"';
                }, $command)));
                
                $process = new Process($command);
                $process->setTimeout(600);
                $process->run();

                if (!$process->isSuccessful()) {
                    Log::error("❌ Error PDFTK grupo '{$groupName}': " . $process->getErrorOutput());
                    Log::error("❌ Salida estándar: " . $process->getOutput());
                } else {
                    Log::info("✅ PDFTK exitoso para grupo '{$groupName}'");
                }
                
                if (file_exists($outputPdfPath)) {
                    $mergedPdfPaths[] = $outputPdfPath;
                    Log::info("✅ PDF generado: " . basename($outputPdfPath) . " (" . filesize($outputPdfPath) . " bytes)");
                } else {
                    Log::error("❌ PDF NO generado para grupo '{$groupName}'");
                }
            }
        }
        
        // 🔍 CHECKPOINT 4: PDFs generados
        Log::info('=== CHECKPOINT 4: PDFS GENERADOS ===');
        Log::info('Total PDFs generados: ' . count($mergedPdfPaths));
        foreach ($mergedPdfPaths as $path) {
            Log::info("PDF: " . basename($path) . " (" . filesize($path) . " bytes)");
        }
        
        if (empty($mergedPdfPaths)) {
            throw new \Exception('No se pudo generar ningún PDF combinado.');
        }

        // 3. Crear ZIP
        $zipPath = $mainTempDir . DIRECTORY_SEPARATOR . 'documentos_unidos.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new \Exception("No se pudo crear el archivo ZIP.");
        }

        // 🔍 CHECKPOINT 5: Creación de ZIP
        Log::info('=== CHECKPOINT 5: CREANDO ZIP ===');
        foreach ($mergedPdfPaths as $pdfPath) {
            if (file_exists($pdfPath)) {
                $zip->addFile($pdfPath, basename($pdfPath));
                Log::info("Añadido al ZIP: " . basename($pdfPath));
            }
        }
        $zip->close();
        
        Log::info('✅ ZIP final: ' . filesize($zipPath) . ' bytes');
        
        // Limpiar subdirectorios
        File::deleteDirectory($uploadsDir);
        File::deleteDirectory($mergedDir);
        
        return response()->download($zipPath, 'documentos_unidos.zip')->deleteFileAfterSend(true);

    } catch (\Exception $e) {
        Log::error('❌ ERROR CRÍTICO: ' . $e->getMessage());
        
        if (File::exists($mainTempDir)) {
            File::deleteDirectory($mainTempDir);
        }
        
        return response()->json(['error' => $e->getMessage()], 500);
    }
}
}
