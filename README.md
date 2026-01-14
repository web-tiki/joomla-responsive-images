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
- Preserves original image **subfolder structure**
- Automatic thumbnail caching
- WebP support (optional)
- Lazy-loading support (optional)
- Layout-based rendering (fully overrideable)
- Secure filesystem handling (no arbitrary writes)
- svg source images support

---

## 📦 Installation

1. Download the latest release:
   https://github.com/web-tiki/joomla-responsive-images/releases/latest/download/responsiveimages.zip
   [All releases](https://github.com/web-tiki/joomla-responsive-images/releases)

2. Install via **Extensions → Install**

3. Enable the plugin:
   ```
   System → Responsive Images
   ```

---

## ⚙️ Configuration

### Thumbnail directory (IMPORTANT)

**Setting:** `Thumbnail directory (relative to /images)`

This setting controls where generated thumbnails are stored.

### ✅ Correct value
```
thumbnails/responsive
```

### ❌ Incorrect value
```
images/thumbnails/responsive
```

> ⚠️ The path is **always relative to Joomla’s `/images` folder**.  
> Do **NOT** include `images/` in this setting.

---

### 📂 Folder structure preservation

The plugin **automatically preserves the original image subfolder structure**.

#### Example

Original image:
```
/images/new york/parc/parc 1.jpg
```

Generated thumbnails (default directory : responsive-images ):
```
/images/responsive-images/new york/parc/parc-<hash>-q75-640x427.jpg
/images/responsive-images/new york/parc/parc-<hash>-q75-1280x854.webp
```

✔ Same subfolders  
✔ Safe paths  
✔ CDN-friendly URLs  

---

## 🧩 Usage

### Basic usage (template or override)

```php
use Joomla\CMS\Layout\LayoutHelper;

echo LayoutHelper::render(
    'responsiveimages.image',
    [
        'field'   => $field,
        'options' => [
            'sizes' => '(min-width: 1024px) 50vw, 100vw'
        ]
    ],
    JPATH_PLUGINS . '/system/responsiveimages/layouts'
);
```

---

## 🧩 Usage with ALL available options

```php
use Joomla\CMS\Layout\LayoutHelper;

echo LayoutHelper::render(
    'responsiveimages.image',
    [
        'field' => $field,
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
---
## 🧠 Option reference

| Option | Type | Description |
|------|------|-------------|
| `lazy` | bool | Enables `loading="lazy"` |
| `webp` | bool | Generates WebP sources |
| `alt` | string | alt text |
| `sizes` | string | HTML sizes attribute |
| `widths` | array | Thumbnail widths |
| `quality` | int | Image quality (1–100) |
| `aspectRatio` | float | Crop ratio (height / width) |

---

## 🎨 Layout Overrides

Default layout:
```
plugins/system/responsiveimages/layouts/responsiveimages/image.php
```

Template override:
```
templates/YOUR_TEMPLATE/html/layouts/responsiveimages/image.php
```

---

## 🔐 Security Design

- Thumbnails always stay inside `/images`
- Original images must be inside `/images`
- Sanitized paths
- No directory traversal
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
- SVG
- WebP (optional)

---

## 🧪 Compatibility

| Joomla Version | Status |
|---------------|--------|
| Joomla 6.x | ✅ |
| Joomla 5.x | ✅ |

---

## ✅ Requirements

Server :

- PHP ≥ 8.1
- Imagick PHP extension enabled
- ImageMagick compiled with support for:
  - JPEG
  - PNG
  - WebP (recommended)

**ℹ️ Without Imagick, the plugin cannot generate thumbnails and will not function.**

You can verify Imagick with:
```
php -m | grep imagick
```

## 📄 License

GPL v2 or later

---
Created by [web-tiki](https://web-tiki.com/)
