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
   https://github.com/web-tiki/joomla-responsive-images/releases/latest/download/responsiveimages.zip

2. Install via **Extensions → Install**
3. Enable the plugin:
   ```
   System → Responsive Images
   ```

---

## ⚙️ Configuration

### Thumbnail directory (IMPORTANT)

Path is **relative to `/images`**.

✅ `thumbnails/responsive`  
❌ `images/thumbnails/responsive`

---

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
    'outputDir'   => 'responsive-images',
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

---

## 🔐 Security

- Thumbnails stay inside `/images`
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
- SVG (SVG images are not resized and are rendered as <img> elements)
- WebP (optional)

---

## 🧪 Requirements

- PHP ≥ 8.1
- Imagick enabled

---

## 📄 License

GPL v2 or later

---
Created by [web-tiki](https://web-tiki.com/)
