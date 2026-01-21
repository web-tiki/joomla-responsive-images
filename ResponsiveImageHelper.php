<?php

class ResponsiveImageHelper {
    private static $metaFilePath;
    private static $metaData;

    public static function getMetaPath() {
        return self::$metaFilePath ?: (self::$metaFilePath = __DIR__ . '/meta.json');
    }

    public static function loadMeta() {
        if (self::$metaData === null) {
            if (file_exists(self::getMetaPath())) {
                self::$metaData = json_decode(file_get_contents(self::getMetaPath()), true);
            } else {
                self::$metaData = array();
            }
        }
        return self::$metaData;
    }

    public static function validateMeta($thumbnail) {
        $meta = self::loadMeta();
        return isset($meta[$thumbnail]) && $meta[$thumbnail]['exists'];
    }

    public static function allThumbnailsExist($thumbnails) {
        foreach ($thumbnails as $thumbnail) {
            if (!self::validateMeta($thumbnail)) {
                return false;
            }
        }
        return true;
    }

    public static function writeMeta($thumbnail, $exists) {
        $meta = self::loadMeta();
        $meta[$thumbnail] = ['exists' => $exists];
        file_put_contents(self::getMetaPath(), json_encode($meta, JSON_PRETTY_PRINT));
    }
}
?>