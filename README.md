# Joomla plugin responsive images

![Joomla Version](https://img.shields.io/badge/Joomla-5.x-blue.svg)
![Joomla Version](https://img.shields.io/badge/Joomla-6.x-blue.svg)
![License](https://img.shields.io/badge/License-MIT-green.svg)
![Build Status](https://github.com/web-tiki/joomla-responsive-images/actions/workflows/release.yml/badge.svg)

A high-performance system plugin for Joomla 6 that generates responsive, retina-ready images with WebP support, lazy loading, and intelligent caching. Designed specifically for modern Joomla 6 environments.

## 🛠 Usage

To use the helper in your Joomla template overrides (e.g., in `com_content` articles or custom modules):

### 1. Initialize the Helper
Add the namespace at the top of your PHP override file:

    use WebTiki\Plugin\System\ResponsiveImages\ResponsiveImageHelper;



### 2. Basic Implementation
Pass the Joomla image field and basic responsive parameters:


    $field = $fields['main-img'] ?? null;
    echo ResponsiveImageHelper::render($field, [
        'sizes'     => '(max-width: 600px) 100vw, 100vw',
        'widths'      => [640,900,1280,1920,3200]
    ]);

### 3. Advanced Implementation (Full Options)
You can override global backend settings directly in the code:

    $field = $fields['main-img'] ?? null;
    echo ResponsiveImageHelper::render($field, [
        'lazy'        => true,             // Enable/Disable lazyloading
        'webp'        => true,             // Enable/Disable WebP generation
        'alt'         => 'Alt text',       // Custom alt attribute
        'sizes'       => '100vw',          // HTML sizes attribute
        'widths'      => [640, 1280],      // Array of widths to generate
        'heights'     => null,             // Array of heights for fixed resizing or null if the images should keep their aspect ratio
        'outputDir'   => 'thumbnails/res', // Folder relative to Joomla root
        'quality'     => 75,               // Image compression (1-100)
        'aspectRatio' => null              // 1 (square), 0.5 (2:1), 2 (1:2). Use null for original.
    ]);


## ⚙️ Plugin Configuration

Install the plugin and navigate to System > Manage > Plugins > System - Responsive Images to set global defaults:
- Thumbnail Directory: The default path where generated images are stored.
- Global Quality: Default compression level for WebP and JPEG.
- Lazyload: Toggle native browser lazy loading by default.

## 🚀 How to Release an Update (GitHub Actions)

This project uses GitHub Actions to automate releases.

1. Update Versions: Increment the version number in 
   1. `update.xml`
   2. `responsiveimages/responsiveimages.xml`.
2. Commit & Push: Push your changes to the `main` branch.
3. Create Tag: Tag the commit using the "v" prefix:
    `git tag v0.0.3
     git push origin v0.0.3`

4. Download: The system will automatically build the `responsiveimages.zip` and publish it to the [Releases page](https://github.com/web-tiki/joomla-responsive-images/releases).

## 🔗 Important Links

- Update Server URL: https://raw.githubusercontent.com/web-tiki/joomla-responsive-images/main/update.xml
- Latest ZIP Download: https://github.com/web-tiki/joomla-responsive-images/releases/latest/download/responsiveimages.zip

-----
[Developed by web-tiki](https://web-tiki.com/).