# Joomla Responsive Images Plugin

![Joomla Version](https://img.shields.io/badge/Joomla-5.x-blue.svg)
![Joomla Version](https://img.shields.io/badge/Joomla-6.x-blue.svg)

![Build Status](https://github.com/web-tiki/joomla-responsive-images/actions/workflows/release.yml/badge.svg)
![Latest version](https://img.shields.io/github/v/release/web-tiki/joomla-responsive-images)
![Unreleased commits](https://img.shields.io/github/commits-since/web-tiki/joomla-responsive-images/latest)

A Joomla **system plugin** that generates **responsive images** (`srcset`, `sizes`, `<picture>`) from image custom fields and template overrides, with **safe, cacheable thumbnail generation**.

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

2. Install via **Extensions → Install**
3. Enable the plugin:
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
            'aspectRatio' => 1.777
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

## 🧪 Requirements

- PHP ≥ 8.1
- Imagick enabled
- Space on your server. This plugin can generate many thumbnails from your images and therefore needs space to write them on your server.

---

## 📄 License

GPL v2 or later

---
Created by [web-tiki](https://web-tiki.com/)
