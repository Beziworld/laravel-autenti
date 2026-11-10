<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

/**
 * Content of a document process file together with its metadata.
 */
final readonly class DownloadedFile
{
    public function __construct(
        public FileInfo $file,
        public string $contents,
        public ?string $mimeType = null,
    ) {}

    public function filename(): string
    {
        return $this->file->filename ?? 'document.pdf';
    }

    public function size(): int
    {
        return strlen($this->contents);
    }

    /**
     * Writes the content to a local path and returns the number of bytes written.
     */
    public function saveTo(string $path): int
    {
        $written = file_put_contents($path, $this->contents);

        if ($written === false) {
            throw new \RuntimeException(sprintf('Cannot write the Autenti file to "%s".', $path));
        }

        return $written;
    }
}
