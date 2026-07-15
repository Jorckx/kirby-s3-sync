<?php
use Kirby\Cms\FileVersion as FileVersion;
use Kirby\Image\Dimensions as Dimensions;
use Kirby\Cms\App as Kirby;

return [
  // Custom URL component to use CDN when available
  'file::url' => function (Kirby $kirby, $file) {
      $key = $file->content()->get('s3_key')->value();
    if ($key) {
      return option('s3.cdn') . '/' . $key;
    }
    return $kirby->nativeComponent('file::url')($kirby, $file);
  },

  // Custom dimensions component to use stored values when available
  'file::dimensions' => function (Kirby $kirby, $file) {
    $key = $file->content()->get('s3_key')->value();
    if ($key) {
      $width  = $file->content()->get('s3_width')->value();
      $height = $file->content()->get('s3_height')->value();
      if ($width && $height) {
        return new Dimensions((int)$width, (int)$height);
      }
    }
    return $kirby->nativeComponent('file::dimensions')($kirby, $file);
  },

  // Custom version component to use CDN when available
  'file::version' => function (Kirby $kirby, $file, array $options = []) {
    $key = $file->content()->get('s3_key')->value();
    if (!$key) {
      return $kirby->nativeComponent('file::version')($kirby, $file, $options);
    }

    // Build Cloudflare Image Resizing URL
    $params = [];
    if (!empty($options['width']))  $params[] = 'width='  . $options['width'];
    if (!empty($options['height'])) $params[] = 'height=' . $options['height'];
    if (!empty($options['quality'])) $params[] = 'quality=' . $options['quality'];
    $params[] = 'format=auto'; // serve webp/avif automatically

    if (!empty($options['crop'])) {
      $params[] = 'fit=crop';
    } elseif (!empty($options['fit'])) {
      $params[] = 'fit=' . $options['fit'];
    }

    $transform = implode(',', $params);
    $base      = option('s3.cdn');

    $url = $transform
      ? "{$base}/cdn-cgi/image/{$transform}/{$key}"
      : "{$base}/{$key}";

    return new FileVersion([
      'modifications' => $options,
      'original'      => $file,
      'url'           => $url,
    ]);
  },
];
