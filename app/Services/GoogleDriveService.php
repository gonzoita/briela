<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    private static ?Drive $drive = null;
    private static string $rootFolderId = '';

    private static function client(): Drive
    {
        if (static::$drive) return static::$drive;

        $credPath = env(
            'GOOGLE_DRIVE_CREDENTIALS_PATH',
            storage_path('app/private/google-drive.json')
        );

        $client = new Client();
        $client->setAuthConfig($credPath);
        $client->addScope(Drive::DRIVE);
        $client->setApplicationName('SGI Interfrigo');

        static::$drive        = new Drive($client);
        static::$rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');

        return static::$drive;
    }

    /** Sube un UploadedFile. Retorna ['id', 'url', 'view', 'name', 'mime'] */
    public static function upload(
        UploadedFile $file,
        string $carpeta = '',
        ?string $nombre = null
    ): array {
        $drive  = static::client();
        $nombre = $nombre ?? $file->getClientOriginalName();
        $mime   = $file->getMimeType();

        $parents = $carpeta
            ? [static::obtenerOCrearCarpeta($carpeta)]
            : [static::$rootFolderId];

        $metadata = new DriveFile(['name' => $nombre, 'parents' => $parents]);

        $resultado = $drive->files->create($metadata, [
            'data'               => file_get_contents($file->getRealPath()),
            'mimeType'           => $mime,
            'uploadType'         => 'multipart',
            'fields'             => 'id,name,mimeType,webViewLink',
            'supportsAllDrives'  => true,
        ]);

        $drive->permissions->create(
            $resultado->getId(),
            new Permission(['type' => 'anyone', 'role' => 'reader']),
            ['supportsAllDrives' => true]
        );

        return [
            'id'   => $resultado->getId(),
            'name' => $resultado->getName(),
            'mime' => $mime,
            'url'  => static::urlDirecta($resultado->getId(), $mime),
            'view' => $resultado->getWebViewLink(),
        ];
    }

    /** Sube desde ruta local (para archivos ya guardados en el servidor) */
    public static function uploadFromPath(
        string $rutaLocal,
        string $carpeta = '',
        ?string $nombre = null
    ): array {
        if (!file_exists($rutaLocal)) {
            throw new \Exception("Archivo no encontrado: {$rutaLocal}");
        }

        $mime   = mime_content_type($rutaLocal);
        $nombre = $nombre ?? basename($rutaLocal);
        $drive  = static::client();

        $parents = $carpeta
            ? [static::obtenerOCrearCarpeta($carpeta)]
            : [static::$rootFolderId];

        $metadata = new DriveFile(['name' => $nombre, 'parents' => $parents]);

        $resultado = $drive->files->create($metadata, [
            'data'              => file_get_contents($rutaLocal),
            'mimeType'          => $mime,
            'uploadType'        => 'multipart',
            'fields'            => 'id,name,mimeType,webViewLink',
            'supportsAllDrives' => true,
        ]);

        $drive->permissions->create(
            $resultado->getId(),
            new Permission(['type' => 'anyone', 'role' => 'reader']),
            ['supportsAllDrives' => true]
        );

        return [
            'id'   => $resultado->getId(),
            'name' => $nombre,
            'mime' => $mime,
            'url'  => static::urlDirecta($resultado->getId(), $mime),
            'view' => $resultado->getWebViewLink(),
        ];
    }

    /** Elimina un archivo de Drive por su ID */
    public static function delete(string $fileId): bool
    {
        try {
            static::client()->files->delete($fileId, ['supportsAllDrives' => true]);
            return true;
        } catch (\Exception $e) {
            Log::warning('GoogleDrive delete falló: ' . $e->getMessage());
            return false;
        }
    }

    /** URL directa según tipo MIME */
    public static function urlDirecta(string $fileId, string $mime = ''): string
    {
        if (str_starts_with($mime, 'image/')) {
            return "https://drive.google.com/uc?export=view&id={$fileId}";
        }
        if (str_starts_with($mime, 'video/')) {
            return "https://drive.google.com/file/d/{$fileId}/preview";
        }
        return "https://drive.google.com/file/d/{$fileId}/view";
    }

    /** Extrae el fileId de una URL de Google Drive (o null si no es URL de Drive) */
    public static function extraerFileId(string $url): ?string
    {
        if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Obtiene o crea una subcarpeta dentro del root de Drive */
    public static function obtenerOCrearCarpeta(string $nombre): string
    {
        $drive  = static::client();
        $rootId = static::$rootFolderId;

        $q = "name='{$nombre}' and mimeType='application/vnd.google-apps.folder'"
           . " and '{$rootId}' in parents and trashed=false";

        $lista = $drive->files->listFiles([
            'q'                         => $q,
            'fields'                    => 'files(id)',
            'supportsAllDrives'         => true,
            'includeItemsFromAllDrives' => true,
        ]);
        if (count($lista->getFiles()) > 0) {
            return $lista->getFiles()[0]->getId();
        }

        $metadata = new DriveFile([
            'name'     => $nombre,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$rootId],
        ]);
        $carpeta = $drive->files->create($metadata, ['fields' => 'id', 'supportsAllDrives' => true]);
        return $carpeta->getId();
    }
}
