# Joomla Responsive Images Plugin

![Joomla Version](https://img.shields.io/badge/Joomla-5.x-blue.svg)
![Joomla Version](https://img.shields.io/badge/Joomla-6.x-blue.svg)

![Build Status](https://github.com/web-tiki/joomla-responsive-images/actions/workflows/release.yml/badge.svg)
![Latest version](https://img.shields.io/github/v/release/web-tiki/joomla-responsive-images)
![Unreleased commits](https://img.shields.io/github/commits-since/web-tiki/joomla-responsive-images/latest)

A Joomla **system plugin** that generates **responsive images** (`srcset`, `sizes`, `<picture>`) from image custom fields and template overrides, with **safe, cacheable thumbnail generation**.
The plugin generates thumbnails on first page load and reuses them on subsequent page loads.

Compatible with **Joomla 5 & Joomla 6**.

---

## ✨ Features

* Responsive image generation (`srcset`, `sizes`) with `<picture>` output
* **Width-based thumbnails** with automatic scaling
* **Never upscales images** beyond original dimensions
* Automatic **fallback image** generation
* Preserves original image **subfolder structure**
* Hash-based cache invalidation
* Optional **WebP** support
* Optional **lazy-loading**
* Full template/layout override support
* **SVG support** (SVG images bypass resizing)
* Safe filesystem handling with locks and TTL

---

## 📦 Installation

1. **Download the latest release:**
   [https://github.com/web-tiki/joomla-responsive-images/releases](https://github.com/web-tiki/joomla-responsive-images/releases)
2. Install via **Extensions → Install**
3. Enable the plugin:

   ```
   System → web-tiki Responsive Images
   ```

---

## ⚙️ Configuration

### Original images and thumbnail directory

* Original images must be in `/images/`, `/media/` or `/templates/` folders
* Thumbnails are created in `/media/ri-responsiveimages/` and mirror the original folder structure

Example: `/images/parcs/new york/` → `/media/ri-responsiveimages/parcs/new york/`

### Debug mode

* Displays debug information on the frontend (breaks layout!)
* Disabled by default for production
* Ensure cache is disabled to see accurate debug info

---

## 🧩 Usage

### Basic usage (template override)

```php
use Joomla\CMS\Layout\LayoutHelper;

echo LayoutHelper::render(
    'responsiveimages.image',
    ['imageField' => $imageField],
    JPATH_PLUGINS . '/system/responsiveimages/layouts'
);
```

**Default options used:**

```php
$defaults = [
    'lazy'        => true,
    'webp'        => true,
    'sizes'       => '100vw',
    'widths'      => [480, 800, 1200, 1600, 2000, 2560],
    'quality'     => 75,
    'alt'         => '',
    'aspectRatio' => null,
];
```

* Original image aspect ratio is respected
* Media field `alt` is used if available

---

### Advanced usage with custom options

```php
use Joomla\CMS\Layout\LayoutHelper;

echo LayoutHelper::render(
    'responsiveimages.image',
    [
        'imageField' => $imageField,
        'options' => [
            'lazy' => true,
            'webp' => false,
            'alt' => 'Custom fallback alt text',
            'sizes' => '(max-width: 650px)  92vw,
                        (max-width: 950px)  44vw,
                        (max-width: 1080px) 28vw,
                        (max-width: 1350px) 25.4vw,
                        (max-width: 1500px) 23.4vw,
                        21.4vw',
            'widths' => [300, 400, 500, 700, 950, 1600],
            'quality' => 85,
            'aspectRatio' => 1.777,
            'imageClass' => 'responsive-image',
        ]
    ],
    JPATH_PLUGINS . '/system/responsiveimages/layouts'
);
```

### Alt text priority

1. Media field `alt` text
2. `alt` option from override
3. Image filename

### WebP option

* When enabled, only `.webp` thumbnails are generated
* A fallback with original image format (.jpg of .png) is generated

---

## 🧠 Options Reference

| Option      | Type   | Description                    |
| ----------- | ------ | ------------------------------ |
| lazy        | bool   | `loading="lazy"` attribute     |
| webp        | bool   | Generate WebP thumbnails       |
| alt         | string | Fallback alt text              |
| sizes       | string | `sizes` attribute for srcset   |
| widths      | array  | Thumbnail widths to generate   |
| quality     | int    | JPEG/WebP quality 1–100        |
| aspectRatio | float  | Fixed height/width ratio       |
| debug       | bool   | Display debug info on frontend |
| imageClass  | string | CSS class for `<img>`          |

---

## 🔄 How It Works (Pipeline)

1. **Input:** original image from media field
2. **Options merge:** default plugin options + override options
3. **Original image metadata extraction:** width, height, hash, extension
4. **If original image is SVG:** quick exist without generating anything. SVG image is displayed with the `<img/>` tag
5. **Aspect ratio handling:** crop box computed if aspectRatio option is set
6. **Get the Requested thumbnails:**

   * All requested widths
   * Widths > original image → generate original-size thumbnail and stop
   * Fallback: largest width capped at original width and 1280px
6. **Manifest loading:** `.manifest.json` file for caching 
7. **If manifest is up to date:** got to step 10
8. **Thumbnail generation:** using Imagick, locks ensure concurrent safety, TTL prevents stale locks
9. **Manifest updated and saved**
10. **Final HTML response:** `<picture>` element with `<source>` and `<img>` tags, including fallback and srcset

---

## 🔐 Security

* Thumbnails generated only inside `/media/ri-responsiveimages/`
* Safe concurrent generation using lock files and TTL
* Hash-based invalidation prevents stale thumbnails

---

## 🚀 Performance & Caching

* Thumbnails generated **once** per hash + width + quality
* Subfolder mirroring improves filesystem performance
* Locking prevents multiple simultaneous generation

---

## 🛠️ Supported Formats

* JPEG
* PNG
* SVG (not resized, output as `<img>`)
* WebP (optional)

---

## 🧪 Requirements

* PHP ≥ 8.2
* Imagick enabled
* Sufficient disk space for thumbnails

---

## 📄 License

GPL v2 or later

---

Created by [web-tiki](https://web-tiki.com/)
