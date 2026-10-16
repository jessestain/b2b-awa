<?php
declare(strict_types=1);

namespace GrupoAwamotos\WebpSupport\Model\Product\Gallery;

use Magento\Catalog\Model\Product\Gallery\MimeTypeExtensionMap as CoreMap;

/**
 * Adiciona image/webp ao mapa MIME->extensao do catalogo.
 */
class MimeTypeExtensionMap extends CoreMap
{
    protected $mimeTypeExtensionMap = [
        'image/jpg' => 'jpg',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}
