# Joomla plugin responsive images

## to initiate the plugin, add this :

    // For responsive images plugin
    use WebTiki\Plugin\System\ResponsiveImages\ResponsiveImageHelper;

## Plugin options : 
The plugin is called from template overrides with this code : 


    $field = $fields['main-img'] ?? null;
    echo ResponsiveImageHelper::render($field, [
        'sizes'     => '(max-width: 600px) 100vw, 100vw',
        'widths'      => [640,900,1280,1920,3200]
    ]);

The default options can be set in plugin backend. They can be overriden in the template overrides with these options : 

    $field = $fields['main-img'] ?? null;
    echo ResponsiveImageHelper::render($field, [
        'lazy'        => true,
        'webp'        => true,
        'alt'         => 'Some alt text here',
        'sizes'       => '(max-width: 600px) 100vw, 100vw',
        'widths'      => [640,900,1280,1920,3200],
        'heights'       => null, // Option for height-based resizing (an array of heights in px)
        'outputDir'   => 'thumbnails/responsive',
        'quality'     => 75,
        'aspectRatio' => null // aspectRatio: 1 (square), 0.5 (2:1 landscape), 2 (1:2 portrait). Use null for no cropping.
    ]);


## 🚀 How to Release an Update

1. **Update Versions:** Change version in `update.xml` and `responsiveimages/responsiveimages.xml`.
2. **Push & Tag:** Push to `main` and create a new Git Tag (e.g., `1.0.5`).
3. **Wait for CI:** The pipeline will upload the ZIP to the Package Registry.
4. **Joomla Update:** Joomla will fetch the latest ZIP via the permanent API link

for commit