<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // Importamos el helper de String de Laravel
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

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
}