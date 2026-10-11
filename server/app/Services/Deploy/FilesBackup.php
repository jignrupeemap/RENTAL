<?php
declare(strict_types=1);

namespace App\Services\Deploy;

/**
 * Archives uploaded photos/videos/slider images and config/env.php
 * (spec 46). Uses ZipArchive when PHP has it, otherwise a plain .tar via
 * PharData; media is already compressed, so nothing is lost either way.
 * Restoring is unpacking the archive back into public_html/uploads.
 */
final class FilesBackup
{
    /** @return array{file: string, files: int, bytes: int} */
    public static function archive(string $base, string $uploadsDir, string $envFile): array
    {
        $list = [];
        if (is_dir($uploadsDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploadsDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $list['uploads/' . substr($f->getPathname(), strlen(rtrim($uploadsDir, '/')) + 1)] = $f->getPathname();
                }
            }
        }
        if (is_file($envFile)) {
            $list['config/env.php'] = $envFile;
        }

        if (class_exists(\ZipArchive::class)) {
            $file = $base . '.zip';
            $zip = new \ZipArchive();
            if ($zip->open($file . '.part', \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create ' . $file);
            }
            foreach ($list as $name => $path) {
                $zip->addFile($path, $name);
                $zip->setCompressionName($name, \ZipArchive::CM_STORE);
            }
            if (!$zip->close()) {
                throw new \RuntimeException('Cannot write ' . $file);
            }
        } else {
            $file = $base . '.tar';
            $tar = new \PharData($file . '.part.tar');
            foreach ($list as $name => $path) {
                $tar->addFile($path, $name);
            }
            unset($tar);
            rename($file . '.part.tar', $file . '.part');
        }
        rename($file . '.part', $file);
        @chmod($file, 0600);
        return ['file' => $file, 'files' => count($list), 'bytes' => (int) filesize($file)];
    }

    /** Keep the newest $keep files-*.zip/.tar archives. */
    public static function prune(string $dir, int $keep): int
    {
        $files = array_merge(glob(rtrim($dir, '/') . '/files-*.zip') ?: [], glob(rtrim($dir, '/') . '/files-*.tar') ?: []);
        usort($files, static fn ($a, $b) => strcmp(basename($b), basename($a)));
        $removed = 0;
        foreach (array_slice($files, max(1, $keep)) as $old) {
            if (@unlink($old)) {
                $removed++;
            }
        }
        return $removed;
    }
}
