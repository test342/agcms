<?php namespace AGCMS\Entity;

use AGCMS\ORM;

class File extends AbstractEntity
{
    /**
     * Table name in database.
     */
    const TABLE_NAME = 'files';

    // Backed by DB
    /** @var string File path. */
    private $path;

    /** @var string File mime. */
    private $mime;

    /** @var int File byte size. */
    private $size;

    /** @var string Text description of file. */
    private $description = '';

    /** @var int Object width in px. */
    private $width = 0;

    /** @var int Object height in px. */
    private $height = 0;

    /**
     * Construct the entity.
     *
     * @param array $data The entity data
     */
    public function __construct(array $data)
    {
        $this->setPath($data['path'])
            ->setMime($data['mime'])
            ->setSize($data['size'])
            ->setDescription($data['description'])
            ->setWidth($data['width'])
            ->setHeight($data['height'])
            ->setId($data['id'] ?? null);
    }

    /**
     * Map data from DB table to entity.
     *
     * @param array The data from the database
     *
     * @return array
     */
    public static function mapFromDB(array $data): array
    {
        return [
            'id'          => $data['id'],
            'path'        => $data['path'],
            'mime'        => $data['mime'],
            'size'        => $data['size'],
            'description' => $data['alt'],
            'width'       => $data['width'],
            'height'      => $data['height'],
        ];
    }

    // Getters and setters

    /**
     * Set path.
     *
     * @param string $path The file path
     *
     * @return self
     */
    private function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Return the file path.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Set the mime type.
     *
     * @param string $mime The mime type
     *
     * @return self
     */
    public function setMime(string $mime): self
    {
        $this->mime = $mime;

        return $this;
    }

    /**
     * Get the mime type.
     *
     * @return string
     */
    public function getMime(): string
    {
        return $this->mime;
    }

    /**
     * Set the file size.
     *
     * @param int $size The file size in bytes
     *
     * @return self
     */
    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Get the file size.
     *
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Set the file text description.
     *
     * @param string $description Text description
     *
     * @return self
     */
    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get the text description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    public function setWidth(int $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function setHeight(int $height): self
    {
        $this->height = $height;

        return $this;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    // ORM related functions

    /**
     * Get data in array format for the database.
     *
     * @return string[]
     */
    public function getDbArray(): array
    {
        return [
            'path'   => db()->eandq($this->path),
            'mime'   => db()->eandq($this->mime),
            'size'   => (string) $this->size,
            'alt'    => db()->eandq($this->description),
            'width'  => (string) $this->width,
            'height' => (string) $this->height,
        ];
    }

    /**
     * Rename file.
     */
    public function move(string $path): bool
    {
        //Rename/move or give an error
        if (!rename(_ROOT_ . $this->getPath(), _ROOT_ . $path)) {
            return false;
        }

        replacePaths($this->getPath(), $path);
        $this->setPath($path)->save();

        return true;
    }

    /**
     * Create new File from a file path.
     *
     * @param string $path The file path
     *
     * @return self
     */
    public static function fromPath(string $path): self
    {
        $imagesize = @getimagesize(_ROOT_ . $path);

        $file = new self([
            'path'        => $path,
            'mime'        => get_mime_type(_ROOT_ . $path),
            'size'        => filesize(_ROOT_ . $path),
            'description' => '',
            'width'       => $imagesize[0] ?? 0,
            'height'      => $imagesize[1] ?? 0,
        ]);

        return $file;
    }

    /**
     * Delete entity and file.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (unlink(_ROOT_ . $this->path)) {
            return parent::delete();
        }

        return false;
    }

    /**
     * Find entity by file path.
     *
     * @param string $path The file path
     */
    public static function getByPath(string $path): ?self
    {
        return ORM::getOneByQuery(
            self::class,
            'SELECT * FROM `' . self::TABLE_NAME . "` WHERE path = '" . db()->esc($path) . "'"
        );
    }
}
