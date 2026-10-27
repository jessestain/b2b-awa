<?php
declare(strict_types=1);

namespace GrupoAwamotos\WebpSupport\Model\Api;

use Magento\Framework\Api\ImageProcessor as CoreImageProcessor;

/**
 * Adiciona image/webp ao mapa MIME->extensao do processador de imagens da API.
 * Necessario quando o nome do arquivo enviado nao traz extensao (neste caso o
 * core lancaria InputException "Cannot recognize image extension.").
 */
class ImageProcessor extends CoreImageProcessor
{
    protected $mimeTypeExtensionMap = [
        'image/jpg' => 'jpg',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}
