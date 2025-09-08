import React, { useState, useCallback, useEffect } from 'react';
// CORRECCIÓN: Asegurarse de que todos los iconos estén importados
import { Upload, FileText, ArrowUpDown, Download, Trash2, AlertTriangle, Edit3 } from 'lucide-react';
import { DragDropContext, Droppable, Draggable } from 'react-beautiful-dnd';
import axios from 'axios';
import { useDropzone } from 'react-dropzone';

// ... (const dropzoneStyles no cambia) ...
const dropzoneStyles = `
.file-drop-zone {
  border: 2px dashed #d1d5db;
  border-radius: 1rem;
  padding: 2rem;
  text-align: center;
  transition: all 0.2s ease-in-out;
}
.file-drop-zone-active {
  border-color: #2563eb;
  background-color: #eff6ff;
}
`;

function PdfUploader() {
  const [files, setFiles] = useState([]);
  // ... (otros estados no cambian) ...
  const [isUploading, setIsUploading] = useState(false);
  const [uploadStatus, setUploadStatus] = useState('');
  const [customOrder, setCustomOrder] = useState('');
  const [validationError, setValidationError] = useState('');
  const [outputFilename, setOutputFilename] = useState('');


  // La función handleDrop se mantiene, la usaremos con el hook
  const handleDrop = useCallback((acceptedFiles) => {
    // ... (la lógica interna de handleDrop no cambia) ...
    setValidationError('');
    const pdfFiles = acceptedFiles.filter(file => file.type === 'application/pdf');
    
    const allNames = new Set(files.map(f => f.name));
    const renamedFilesInfo = [];

    const newFiles = pdfFiles.map(file => {
      const nameWithoutExt = file.name.replace(/\.pdf$/i, '');
      let finalName = nameWithoutExt;

      if (allNames.has(finalName)) {
        let counter = 1;
        let newNameAttempt = `${nameWithoutExt}-${counter}`;
        while (allNames.has(newNameAttempt)) {
          counter++;
          newNameAttempt = `${nameWithoutExt}-${counter}`;
        }
        finalName = newNameAttempt;
        renamedFilesInfo.push(`'${nameWithoutExt}' fue renombrado a '${finalName}'`);
      }
      
      allNames.add(finalName);

      return {
        id: Math.random().toString(36).substr(2, 9),
        file,
        name: finalName,
        size: file.size,
      };
    });

    if (renamedFilesInfo.length > 0) {
      setValidationError(`Se renombraron duplicados: ${renamedFilesInfo.join('; ')}.`);
    }

    setFiles(prev => [...prev, ...newFiles]);
  }, [files]);
  
  // NUEVO: Configuración del hook useDropzone
  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop: handleDrop,
    accept: { 'application/pdf': ['.pdf'] },
    noClick: true, // Desactivamos el clic para usar nuestro propio botón/label
    noKeyboard: true,
  });

  // ... (el resto de las funciones no cambian) ...
    useEffect(() => {
    if (validationError) {
      const timer = setTimeout(() => setValidationError(''), 5000);
      return () => clearTimeout(timer);
    }
  }, [validationError]);

  const StyleInjector = ({ css }) => <style>{css}</style>;
  
  const handleFileInput = (e) => { 
    const selectedFiles = Array.from(e.target.files);
    handleDrop(selectedFiles);
    e.target.value = '';
  };
  const handleOnDragEnd = (result) => {
    if (!result.destination) return;
    const items = Array.from(files);
    const [reorderedItem] = items.splice(result.source.index, 1);
    items.splice(result.destination.index, 0, reorderedItem);
    setFiles(items);
  };
  const applyCustomOrder = () => {
    setValidationError('');
    if (!customOrder.trim()) return;

    const orderArray = customOrder.split('\n').map(line => line.replace(/\s/g, '')).filter(Boolean);
    const fileMap = new Map(files.map(f => [f.name.replace(/\s/g, ''), f]));
    
    const nonExistentNames = orderArray.filter(name => !fileMap.has(name));
    if (nonExistentNames.length > 0) {
      setValidationError(`Los siguientes nombres no existen: ${nonExistentNames.join(', ')}`);
      return;
    }

    const reorderedFiles = [];
    const processedFiles = new Set();

    orderArray.forEach(name => {
      if (fileMap.has(name) && !processedFiles.has(name)) {
        reorderedFiles.push(fileMap.get(name));
        processedFiles.add(name);

        const children = files
          .filter(f => {
            const cleanChildName = f.name.replace(/\s/g, '');
            return cleanChildName.startsWith(`${name}-`) && !processedFiles.has(cleanChildName);
          })
          .sort((a, b) => {
              const numA = parseInt(a.name.replace(/\s/g, '').split('-').pop(), 10);
              const numB = parseInt(b.name.replace(/\s/g, '').split('-').pop(), 10);
              return numA - numB;
          });

        children.forEach(child => {
          reorderedFiles.push(child);
          processedFiles.add(child.name.replace(/\s/g, ''));
        });
      }
    });

    const remainingFiles = files.filter(f => !processedFiles.has(f.name.replace(/\s/g, '')));
    setFiles([...reorderedFiles, ...remainingFiles]);
  };
  const removeFile = (id) => {
    setFiles(files.filter(file => file.id !== id));
  };
  const handleMergeAndDownload = async () => {
    if (files.length === 0) {
      setUploadStatus('❌ No hay archivos para procesar');
      return;
    }
    setIsUploading(true);
    setUploadStatus('🔄 Procesando PDFs...');

    const formData = new FormData();
    const orderArray = files.map(fileItem => fileItem.name);

    files.forEach(fileItem => {
        formData.append('pdfs[]', fileItem.file, `${fileItem.name}.pdf`);
    });

    orderArray.forEach(name => {
        formData.append('order[]', name);
    });

    const finalOutputName = outputFilename.trim() || `documento_ordenado`;
    formData.append('output_name', finalOutputName);


    try {
      const response = await axios.post('/api/merge-pdfs', formData, {
        responseType: 'blob',
      });
      
      const blob = new Blob([response.data], { type: 'application/pdf' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${finalOutputName}_${Date.now()}.pdf`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      a.remove();

      setUploadStatus('✅ ¡PDF combinado descargado exitosamente!');
      setTimeout(() => {
        setFiles([]);
        setUploadStatus('');
        setCustomOrder('');
        setOutputFilename('');
      }, 3000);

    } catch (error) {
      const errorMessage = error.response?.data?.error || "Error de conexión o del servidor.";
      setUploadStatus(`❌ Error: ${errorMessage}`);
    } finally {
      setIsUploading(false);
    }
  };


  return (
    <>
      <StyleInjector css={dropzoneStyles} />
      {/* ... (Header no cambia) ... */}
      <div className="min-h-screen bg-gradient-to-br from-red-50 via-white to-red-50">
      <header className="bg-white shadow-sm border-b">
        <div className="max-w-7xl mx-auto px-4 py-6">
          <div className="flex items-center space-x-3">
            <div className="bg-red-600 p-2 rounded-lg">
              <FileText className="w-6 h-6 text-white" />
            </div>
            <div>
              <h1 className="text-3xl font-bold text-gray-900">PDF Merger Pro</h1>
              <p className="text-gray-600">Ordena y combina tus PDFs fácilmente</p>
            </div>
          </div>
        </div>
      </header>

      <div className="max-w-7xl mx-auto px-4 py-8">
        <div className="grid lg:grid-cols-2 gap-8">
          
          <div className="space-y-6">
            <div className="bg-white rounded-2xl shadow-lg p-8">
              <h2 className="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                <Upload className="w-6 h-6 mr-3 text-red-600" />
                Subir Archivos PDF
              </h2>
              
              {/* NUEVO: Aplicamos los props de dropzone al div principal */}
              <div {...getRootProps()} className={`file-drop-zone mb-6 ${isDragActive ? 'file-drop-zone-active' : ''}`}>
                <Upload className="w-12 h-12 text-gray-400 mx-auto mb-4" />
                {/* NUEVO: El texto cambia dinámicamente */}
                {isDragActive ? (
                  <p className="text-lg font-medium text-red-600 mb-2">
                    ¡Suelta los archivos aquí!
                  </p>
                ) : (
                  <>
                    <p className="text-lg font-medium text-gray-700 mb-2">
                      Arrastra y suelta tus PDFs aquí
                    </p>
                    <p className="text-gray-500 mb-4">o haz clic para seleccionar archivos</p>
                  </>
                )}
                
                {/* NUEVO: Aplicamos los props de input al input original */}
                <input {...getInputProps()} id="file-input" className="hidden" />
                
                <label
                  htmlFor="file-input"
                  className="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg cursor-pointer transition-colors duration-200 font-medium"
                >
                  Seleccionar Archivos
                </label>
              </div>

              {/* ... (el resto del JSX no cambia) ... */}
              {files.length > 0 && (
                <div className="border-t pt-6">
                <h3 className="font-semibold text-gray-800 mb-4 flex items-center">
                    <FileText className="w-5 h-5 mr-2" />
                    Archivos seleccionados ({files.length})
                  </h3>
                  <div className="space-y-2 max-h-64 overflow-y-auto pr-2">
                    {files.map((file, index) => (
                      <div
                        key={file.id}
                        className="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                      >
                        <div className="flex items-center space-x-3 flex-1 overflow-hidden">
                          <span className="bg-red-100 text-red-800 text-sm font-medium px-2 py-1 rounded">
                            {index + 1}
                          </span>
                          <div className="truncate">
                            <p className="font-medium text-gray-800 truncate">{file.name}</p>
                            <p className="text-sm text-gray-500">
                              {(file.size / 1024 / 1024).toFixed(2)} MB
                            </p>
                          </div>
                        </div>
                        <button
                          onClick={() => removeFile(file.id)}
                          className="text-red-500 hover:text-red-700 p-1 ml-2"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>
          <div className="space-y-6">
            <div className="bg-white rounded-2xl shadow-lg p-8">
              <h2 className="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                <ArrowUpDown className="w-6 h-6 mr-3 text-red-600" />
                Definir Orden
              </h2>

              <div className="space-y-4">
                <p className="text-gray-600">
                  Escribe los nombres de los PDFs (sin extensión) en el orden que deseas:
                </p>
                
                <textarea
                  value={customOrder}
                  onChange={(e) => setCustomOrder(e.target.value)}
                  placeholder="Ejemplo:&#10;5&#10;10&#10;20&#10;5120&#10;11"
                  className="w-full h-32 p-4 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none font-mono text-sm"
                />

                {validationError && (
                  <div className="p-3 bg-yellow-100 text-yellow-800 border border-yellow-200 rounded-lg flex items-center space-x-2">
                    <AlertTriangle className="w-5 h-5" />
                    <span>{validationError}</span>
                  </div>
                )}

                <button
                  onClick={applyCustomOrder}
                  disabled={!customOrder.trim() || files.length === 0}
                  className="w-full bg-red-600 hover:bg-red-700 disabled:bg-gray-400 text-white py-3 px-6 rounded-lg transition-colors duration-200 font-medium"
                >
                  Aplicar Orden Personalizado
                </button>
              </div>

              {files.length > 0 && (
                <div className="mt-8 border-t pt-6">
                  <h3 className="font-semibold text-gray-800 mb-4">
                    Vista previa del orden (puedes arrastrar para reordenar):
                  </h3>
                  <DragDropContext onDragEnd={handleOnDragEnd}>
                    <Droppable droppableId="files">
                      {(provided) => (
                        <div
                          {...provided.droppableProps}
                          ref={provided.innerRef}
                          className="space-y-2"
                        >
                          {files.map((file, index) => (
                            <Draggable key={file.id} draggableId={file.id} index={index}>
                              {(provided, snapshot) => (
                                <div
                                  ref={provided.innerRef}
                                  {...provided.draggableProps}
                                  {...provided.dragHandleProps}
                                  className={`p-3 bg-gradient-to-r from-red-50 to-pink-50 rounded-lg border transition-all duration-200 flex items-center space-x-3 ${
                                    snapshot.isDragging ? 'shadow-lg scale-105' : 'shadow-sm'
                                  }`}
                                >
                                  <span className="bg-red-100 text-red-800 text-sm font-bold px-3 py-1 rounded-full">
                                    {index + 1}
                                  </span>
                                  <span className="font-medium text-gray-800">
                                    {file.name}
                                  </span>
                                </div>
                              )}
                            </Draggable>
                          ))}
                          {provided.placeholder}
                        </div>
                      )}
                    </Droppable>
                  </DragDropContext>
                </div>
              )}
            </div>
            <div className="bg-white rounded-2xl shadow-lg p-8">
              <div className="space-y-4 mb-6">
                 <h2 className="text-2xl font-semibold text-gray-800 flex items-center">
                    <Edit3 className="w-6 h-6 mr-3 text-red-600" />
                    Nombre del Archivo Final (Opcional)
                  </h2>
                  <input
                    type="text"
                    value={outputFilename}
                    onChange={(e) => setOutputFilename(e.target.value)}
                    placeholder="Ej: reporte_mensual"
                    className="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"
                  />
              </div>

              <button
                onClick={handleMergeAndDownload}
                disabled={files.length === 0 || isUploading}
                className="w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 disabled:from-gray-400 disabled:to-gray-500 text-white py-4 px-8 rounded-xl transition-all duration-300 font-bold text-lg shadow-lg hover:shadow-xl transform hover:-translate-y-1 disabled:transform-none"
              >
                {isUploading ? (
                  <div className="flex items-center justify-center space-x-2">
                    <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-white"></div>
                    <span>Procesando...</span>
                  </div>
                ) : (
                  <div className="flex items-center justify-center space-x-2">
                    <Download className="w-6 h-6" />
                    <span>Combinar y Descargar PDF</span>
                  </div>
                )}
              </button>

              {uploadStatus && (
                <div className={`mt-4 p-4 rounded-lg font-medium text-center ${
                  uploadStatus.includes('✅') 
                    ? 'bg-red-100 text-red-800 border border-red-200' 
                    : uploadStatus.includes('❌')
                    ? 'bg-red-100 text-red-800 border border-red-200'
                    : 'bg-red-100 text-red-800 border border-red-200'
                }`}>
                  {uploadStatus}
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
    </>
  );
}

export default PdfUploader;