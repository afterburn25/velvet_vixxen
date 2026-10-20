<?php
declare(strict_types=1);

namespace App\Core;

use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class AddonArchive
{
    private ?ZipArchive $zip = null;
    private ?PharData $phar = null;

    public function __construct(private readonly string $path)
    {
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('The ZIP package could not be opened.');
            }
            $this->zip = $zip;
            return;
        }
        try {
            $this->phar = new PharData($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('The ZIP package could not be opened. Enable PHP ZIP support or PharData.', 0, $e);
        }
    }

    public function __destruct()
    {
        if ($this->zip instanceof ZipArchive) {
            $this->zip->close();
        }
    }

    /** @return list<array{name:string,size:int,compressed:int,crc:string,directory:bool,symlink:bool}> */
    public function entries(): array
    {
        $entries = [];
        if ($this->zip instanceof ZipArchive) {
            for ($i = 0; $i < $this->zip->numFiles; $i++) {
                $stat = $this->zip->statIndex($i, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat) || !isset($stat['name'])) continue;
                $name = (string) $stat['name'];
                $opsys = 0; $attributes = 0; $symlink = false;
                if ($this->zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                    $symlink = ((($attributes >> 16) & 0xF000) === 0xA000);
                }
                $entries[] = [
                    'name'=>$name,'size'=>(int)($stat['size']??0),'compressed'=>(int)($stat['comp_size']??0),
                    'crc'=>(string)($stat['crc']??''),'directory'=>str_ends_with($name,'/'),'symlink'=>$symlink,
                ];
            }
            return $entries;
        }
        if (!$this->phar instanceof PharData) return [];
        $iterator = new RecursiveIteratorIterator($this->phar, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            /** @var \PharFileInfo $file */
            $full = str_replace('\\','/',$file->getPathname());
            $prefix = 'phar://' . str_replace('\\','/',$this->path) . '/';
            $name = str_starts_with($full,$prefix) ? substr($full,strlen($prefix)) : $file->getFilename();
            $entries[] = [
                'name'=>$name . ($file->isDir()?'/':''),'size'=>$file->isDir()?0:(int)$file->getSize(),
                'compressed'=>0,'crc'=>'','directory'=>$file->isDir(),'symlink'=>$file->isLink(),
            ];
        }
        return $entries;
    }

    public function get(string $name): ?string
    {
        if ($this->zip instanceof ZipArchive) {
            $content = $this->zip->getFromName($name);
            return is_string($content) ? $content : null;
        }
        if (!$this->phar instanceof PharData || !isset($this->phar[$name])) return null;
        $file = $this->phar[$name];
        if ($file->isDir()) return null;
        $content = file_get_contents($file->getPathname());
        return is_string($content) ? $content : null;
    }

    public function writeEntryTo(string $name, string $target): void
    {
        $content = $this->get($name);
        if ($content === null) throw new RuntimeException('Could not read package entry: ' . $name);
        if (file_put_contents($target, $content, LOCK_EX) === false) throw new RuntimeException('Could not create installed file: ' . $name);
    }

    public static function createFromDirectory(string $source, string $target): void
    {
        @unlink($target);
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create ZIP archive.');
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                $path = $entry->getPathname();
                $local = str_replace('\\','/',substr($path,strlen($source)+1));
                if ($local === '.package.json') continue;
                if ($entry->isDir()) $zip->addEmptyDir($local); else $zip->addFile($path,$local);
            }
            $zip->close();
            return;
        }
        $tarTarget = preg_replace('/\.zip$/i', '.tar', $target) ?: ($target . '.tar');
        @unlink($tarTarget);
        try {
            $archive = new PharData($tarTarget);
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                $path = $entry->getPathname();
                $local = str_replace('\\', '/', substr($path, strlen($source) + 1));
                if ($local === '.package.json') continue;
                if ($entry->isDir()) {
                    $archive->addEmptyDir($local);
                } else {
                    $archive->addFile($path, $local);
                }
            }
            $zipArchive = $archive->convertToData(Phar::ZIP);
            $generated = $zipArchive->getPath();
            unset($zipArchive, $archive);
            @unlink($tarTarget);
            if ($generated !== $target) {
                @unlink($target);
                if (!@rename($generated, $target)) {
                    throw new RuntimeException('Could not move the generated ZIP archive into place.');
                }
            }
        } catch (\Throwable $e) {
            @unlink($tarTarget);
            @unlink($target);
            throw new RuntimeException('Could not create ZIP archive with PharData.', 0, $e);
        }
    }
}
