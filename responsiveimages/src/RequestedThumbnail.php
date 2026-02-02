<?php
declare(strict_types=1);

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ResponsiveImages
 *
 * @copyright   (C) 2026 web-tiki
 * @license     GNU General Public License version 3 or later
 */


namespace WebTiki\Plugin\System\ResponsiveImages;

defined('_JEXEC') or die;

final class RequestedThumbnail
{
    public const ROLE_THUMBNAIL = 'thumbnail';
    public const ROLE_FALLBACK = 'fallback';

    public const ROLES = [
        self::ROLE_THUMBNAIL,
        self::ROLE_FALLBACK,
    ];

    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $filePath,
        public readonly string $extension,
        public readonly int $quality,
        public readonly string $role,
    ) {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Invalid thumbnail role: ' . $role);
        }
    }

    public function getKey(): string
    {
        return implode(':', [
            (string)$this->quality,
            $this->extension, // webp | jpg | png
            $this->width . 'x' . $this->height,
        ]);
    }

    public function isWebp(): bool
    {
        return $this->extension === 'webp';
    }


    public function getSrcsetEntry(): ?string
    {
        if (!$this->filePath) {
            return null;
        }

        
        $filePath = '/' . self::encodeUrlPath(
            str_replace(JPATH_ROOT . '/', '', $this->filePath)
        ) . " {$this->width}w";

        return $filePath;
    }

    public function getUrl(): string
    {
        return '/' . self::encodeUrlPath(
            str_replace(JPATH_ROOT . '/', '', $this->filePath)
        );
    }


    private static function encodeUrlPath(string $path): string
    {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);

        return implode(
            '/',
            array_map('rawurlencode', explode('/', $path))
        );
    }

    public function isFallback(): bool
    {
        return $this->role === self::ROLE_FALLBACK;
    }

    public function isThumbnail(): bool
    {
        return $this->role === self::ROLE_THUMBNAIL;
    }

}
