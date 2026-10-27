<?php

declare(strict_types=1);

namespace GrupoAwamotos\StoreSetup\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Aplica a paleta de cores definitiva AWA Motos no tema Rokanthemes.
 *
 * Design System (13/10/2026):
 * Primária:        #B73337 (laranja)
 * Text Color:      #111111 (preto)
 * Link Color:      #111111
 * Link Hover:      #8e2629
 * Button Text:     #111111 (preto sobre laranja — contraste 6.6:1)
 * Button BG:       #B73337
 * Button Hover BG: #8e2629
 *
 * Estes valores alimentam o template theme_option.phtml que gera CSS inline
 * com !important, portanto têm prioridade máxima na cascata.
 */
class ApplyAwaColorPalette implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;
    private WriterInterface $configWriter;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        WriterInterface $configWriter
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->configWriter = $configWriter;
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $colors = [
            'themeoption/colors/custom'                  => '1',
            'themeoption/colors/text_color'               => '111111',
            'themeoption/colors/link_color'               => 'b73337',
            'themeoption/colors/link_hover_color'         => '8e2629',
            'themeoption/colors/button_text_color'        => 'FFFFFF',
            'themeoption/colors/button_bg_color'          => 'b73337',
            'themeoption/colors/button_hover_text_color'  => 'FFFFFF',
            'themeoption/colors/button_hover_bg_color'    => '8e2629',
        ];

        foreach ($colors as $path => $value) {
            $this->configWriter->save($path, $value);
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
