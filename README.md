# Joomla Responsive Images Plugin

![Joomla Version](https://img.shields.io/badge/Joomla-5.x-blue.svg)
![Joomla Version](https://img.shields.io/badge/Joomla-6.x-blue.svg)

![Build Status](https://github.com/web-tiki/joomla-responsive-images/actions/workflows/release.yml/badge.svg)
![Latest version](https://img.shields.io/github/v/release/web-tiki/joomla-responsive-images)
![Unreleased commits](https://img.shields.io/github/commits-since/web-tiki/joomla-responsive-images/latest)

A Joomla **system plugin** that generates **responsive images** (`srcset`, `sizes`, `<picture>`) from image custom fields and template overrides, with **safe, cacheable thumbnail generation**.
The plugin generates the thumbnails on the first page load and reuses them on future page loads.

Compatible with **Joomla 5 & Joomla 6**.

---

## ✨ Features

- Responsive image generation (`srcset`, `sizes`) with `<picture>` output
- **Width-based thumbnails**
- **Never upscales images**
- Preserves original image **subfolder structure**
- Automatic thumbnail caching
- WebP support (optional)
- Lazy-loading support (optional)
- Layout-based rendering (fully overrideable)
- Secure filesystem handling
- SVG source images support

---

## 📦 Installation

1. **Download the latest release:**
   https://github.com/web-tiki/joomla-responsive-images/releases

3. Install via **Extensions → Install**
4. Enable the plugin:
   ```
   System → Responsive Images
   ```

---

## ⚙️ Configuration

### Original images and thumbnail directory

The original images must be in the default `/images/` folder.
The thumbnails are created in the `/media/ri-responsiveimages/` folder and keep the folder structure of the original image (relative to the `/images/` folder). 

Example : 
An original image in the folder `/images/parcs/new york/` will generate thumbnails in the folder `/media/ri-responsiveimages/parcs/new york/`.

---

### Debug mode

**This breaks layout by displaying debug information on the frontend !**

This is disabled by default and should be on production sites. It is intended to debug the plugin and show where is fails if it does.

**Ensure cache is disabled to get reliable information here**


## 🧩 Usage

### Basic usage (template or override)



```php
use Joomla\CMS\Layout\LayoutHelper;

echo LayoutHelper::render('responsiveimages.image',['imageField' => $imageField],JPATH_PLUGINS . '/system/responsiveimages/layouts');
```
This will use all the default options to generate the thumbnails :
*(Most of these default values are customizable in the plugin options.)*

```php
$defaults = [
    'lazy'        => true,
    'webp'        => true,
    'sizes'       => '100vw',
    'widths'      => '480, 800, 1200, 1600, 2000, 2560',
    'quality'     => 75,
    'alt'         => '',
    'aspectRatio' => null,
];
```

The alt from the image media field will still be used if it exists and the image aspect ratio of the original image will be respected.

---

## 🧩 Usage with ALL available options

```php
use Joomla\CMS\Layout\LayoutHelper;

echo LayoutHelper::render(
    'responsiveimages.image',
    [
        'imageField' => $imageField,
        'options' => [
            'lazy' => true,
            'webp' => true,
            'alt' => 'Custom fallback alt text',
            'sizes' => '(min-width: 1200px) 50vw, 100vw',
            'widths' => [320, 640, 1024, 1600],
            'quality' => 75,
            'aspectRatio' => 1.777,
            'image-class' => 'responsive-image',
        ]
    ],
    JPATH_PLUGINS . '/system/responsiveimages/layouts'
);
```

### Alt text priority

1. Image media field alt text
2. `alt` option from override
3. Image filename


### Webp option

When enabled, the plugin doesn't generate any raster thumbnails (.jpg or .png), only .webp thumbnails are genrated.
The original image is used as a fallback in the `<img src="ORIGINAL-IMAGE-RELATIVE-PATH-HERE" />`.


---

## 🧠 Options

| Option | Type | Description |
|------|------|-------------|
| lazy | bool | loading="lazy" |
| webp | bool | Generate WebP |
| alt | string | Fallback alt |
| sizes | string | sizes attribute |
| widths | array | Thumbnail widths |
| quality | int | 1–100 |
| aspectRatio | float | height / width |
| debug | bool | display debug information on the frontend |

---

## 🔐 Security

- Thumbnails stay inside `/media/ri-responsiveimages/`
- Safe concurrent generation

---

## 🚀 Performance & Caching

- Thumbnails generated once
- Hash-based cache invalidation
- Subfolder mirroring improves FS performance

---

## 🛠️ Supported Formats

- JPEG
- PNG
- SVG (SVG images are not resized and are rendered as `<img>` elements)
- WebP (optional)

---

## 🛠️ The Execution Flow: Phase by Phase

<img width="1408" height="768" alt="Gemini_Generated_Image_8bpr8i8bpr8i8bpr" src="https://github.com/user-attachments/assets/26c5f8c3-fd0a-42c8-b6a5-cae9d00ca945" />

### Phase 1: Context & Security

1. Plugin Check: Verifies the plugin is enabled and retrieves global parameters.
2. Options Merging: Combines the specific image options (from the layout) with the plugin defaults.
3. Path Normalization:
   - Cleans URL encoding.
   - Blocks directory traversal attacks (../).
   - Resolves the Absolute Disk Path using realpath().

### Phase 2: The "Early Exit" (The Fast Path)

This is where the performance boost happens.

1. Key Generation: The plugin creates a unique MD5 Fingerprint using:
   - File path + Modification time (mtime).
   - Widths, Quality, Aspect Ratio, and WebP settings.
2. Manifest Lookup: It reads the manifest.json in the target folder.
   - IF FOUND: It skips all image processing, re-attaches dynamic HTML attributes (like alt and loading="lazy"), and returns the data in < 1ms.

### Phase 3: Processing Scenarios

If the manifest check fails, the plugin branches into three distinct scenarios based on the file type and requirements:

| Step | Scenario A: SVG | Scenario B: Raster (Cached Image) | Scenario C: Raster (New Processing) |
|------|------|-------------|---|
| Detection | Identifies .svg extension. | Identifies JPG/PNG. | Identifies JPG/PNG. |
| Logic | Reads XML for `viewBox` or `width/height`. | Skips Imagick because file exists on disk. | Imagick starts. Crops, resizes, and converts formats. |
| I/O | Zero image manipulation. | Simple file existence check. | Heavy CPU/RAM usage to generate thumbnails. |
| Storage | Writes entry to manifest.json. | Writes entry to manifest.json. | Writes entry to manifest.json. |
| Return | Returns original SVG path. | Returns paths to generated thumbnails. | Returns paths to newly generated thumbnails. |

### The Decision Logic in Detail

1. **The Raster Math**
Before Imagick touches the file, the plugin calculates the "Resize Jobs":
- Aspect Ratio: If a ratio is provided, it calculates a Crop Box centered on the image.
- Deduplication: If the original is 1000px and you ask for 1200px, it caps the width at 1000px to avoid upscaling blur.
2. **The Locking Mechanism** To prevent "Cache Stampede" (where 10 users trigger the same 5-second image resize at once):
- It creates a .lock file.
- The first process to get the lock does the work.
- Other processes wait or skip, ensuring the server doesn't crash under high traffic.

3. **Dynamic Injection**

Notice that alt, sizes, and image-class are added after the manifest check. This allows you to use the same processed image in different modules with different Alt texts or CSS classes without needing to re-process the image or create new manifest entries.

---

## 🧪 Requirements

- PHP ≥ 8.2
- Imagick enabled
- Space on your server. This plugin can generate many thumbnails from your images and therefore needs space to write them on your server.

---

## 📄 License

GPL v2 or later

---
Created by [web-tiki](https://web-tiki.com/)
