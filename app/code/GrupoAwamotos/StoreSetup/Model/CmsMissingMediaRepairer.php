<?php

declare(strict_types=1);

namespace GrupoAwamotos\StoreSetup\Model;

use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

class CmsMissingMediaRepairer
{
    private const MEDIA_DIRECTIVE_PATTERN = '/\{\{\s*media\s+url=(["\']?)([^"\'\s\}]+)\1\s*\}\}/i';
    private const HOME_BANNERS_PREFIX = 'wysiwyg/home-banners/';

    /**
     * Reparos limitados a blocos demo/legados para não alterar o storefront ativo.
     *
     * @var string[]
     */
    private const TARGET_BLOCK_IDENTIFIERS = [
        'banner_mid2_home1',
        'banner_left2_default',
        'banner_category_home_3_1',
        'banner_category_home_3_2',
        'banner_category_home_4_1',
        'banner_category_home_4_2',
        'banner_category_home_4_3',
        'banner_category_home_4_4',
        'banner-storelocator',
        'banner_mid_home4',
        'banner_mid_home6',
        'banner_mid_home7',
        'banner_bottom7',
        'block-topbar-image7',
        'banner2_mid_home10',
        'banner1_mid_home10',
        'banner1_mid_home11',
        'banner_mid_home13',
        'banner1_mid_home15',
        'shipping_support_fashion',
        'shipping_support_fashion_2',
    ];

    /**
     * @var string[]
     */
    private const BANNER_FALLBACKS = [
        'wysiwyg/banner5_1.jpg',
        'wysiwyg/banner5_2.jpg',
        'wysiwyg/banner5_3.jpg',
        'wysiwyg/home-banners/menu-promo.jpg',
    ];

    private const ICON_FALLBACK = 'wysiwyg/logo-awa.png';
    /**
     * Extensões comuns de imagens legadas sem pasta (ex: "banner10-1.jpg").
     *
     * @var string[]
     */
    private const LEGACY_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'svg'];

    public function __construct(
        private readonly BlockCollectionFactory $blockCollectionFactory,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly BlockFactory $blockFactory,
        private readonly PageFactory $pageFactory,
        private readonly DirectoryList $directoryList,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *     blocks:int,
     *     pages:int,
     *     replaced:int,
     *     failed_blocks:string[],
     *     failed_pages:string[]
     * }
     */
    public function repairAll(): array
    {
        $blocks = $this->repairBlocks();
        $pages = $this->repairPages();

        return [
            'blocks' => $blocks['updated'],
            'pages' => $pages['updated'],
            'replaced' => $blocks['replaced'] + $pages['replaced'],
            'failed_blocks' => $blocks['failed'],
            'failed_pages' => $pages['failed'],
        ];
    }

    /**
     * @return array{updated:int,replaced:int,failed:string[]}
     */
    public function repairBlocks(): array
    {
        $updatedCount = 0;
        $replacedCount = 0;
        $failedIdentifiers = [];
        $collection = $this->blockCollectionFactory->create();

        foreach ($collection as $block) {
            $identifier = (string) $block->getIdentifier();

            if (!in_array($identifier, self::TARGET_BLOCK_IDENTIFIERS, true)) {
                continue;
            }

            $content = (string) $block->getContent();
            $repairResult = $this->repairContent($identifier, $content);

            if ($repairResult['content'] === $content) {
                continue;
            }

            try {
                $repairableBlock = $this->blockFactory->create();
                $repairableBlock->load((int) $block->getId());
                $repairableBlock->setContent($repairResult['content']);
                $repairableBlock->save();
                $updatedCount++;
                $replacedCount += $repairResult['replaced'];
            } catch (\Throwable $exception) {
                $failedIdentifiers[] = $identifier;
                $this->logger->error(
                    sprintf(
                        '[CmsMissingMediaRepairer] Erro ao reparar bloco "%s": %s',
                        $identifier,
                        $exception->getMessage()
                    )
                );
            }
        }

        return [
            'updated' => $updatedCount,
            'replaced' => $replacedCount,
            'failed' => $failedIdentifiers,
        ];
    }

    /**
     * @return array{updated:int,replaced:int,failed:string[]}
     */
    public function repairPages(): array
    {
        $updatedCount = 0;
        $replacedCount = 0;
        $failedIdentifiers = [];
        $collection = $this->pageCollectionFactory->create();

        foreach ($collection as $page) {
            $identifier = (string) $page->getIdentifier();
            $content = (string) $page->getContent();
            $repairResult = $this->repairContent($identifier, $content);

            if ($repairResult['content'] === $content) {
                continue;
            }

            try {
                $repairablePage = $this->pageFactory->create();
                $repairablePage->load((int) $page->getId());
                $repairablePage->setContent($repairResult['content']);
                $repairablePage->save();
                $updatedCount++;
                $replacedCount += $repairResult['replaced'];
            } catch (\Throwable $exception) {
                $failedIdentifiers[] = $identifier;
                $this->logger->error(
                    sprintf(
                        '[CmsMissingMediaRepairer] Erro ao reparar página "%s": %s',
                        $identifier,
                        $exception->getMessage()
                    )
                );
            }
        }

        return [
            'updated' => $updatedCount,
            'replaced' => $replacedCount,
            'failed' => $failedIdentifiers,
        ];
    }

    /**
     * @return array{content:string,replaced:int}
     */
    public function repairContent(string $identifier, string $content): array
    {
        $replacementIndex = 0;
        $replacedCount = 0;

        $repairedContent = (string) preg_replace_callback(
            self::MEDIA_DIRECTIVE_PATTERN,
            function (array $matches) use ($identifier, &$replacementIndex, &$replacedCount): string {
                $quote = $matches[1];
                $mediaPath = $this->normalizeMediaPath((string) $matches[2]);
                if ($mediaPath === '') {
                    return $matches[0];
                }

                if ($this->mediaFileExists($mediaPath)) {
                    return $matches[0];
                }

                if (!$this->shouldRepairReference($identifier, $mediaPath)) {
                    return $matches[0];
                }

                $fallbackPath = $this->resolveFallbackPath($identifier, $mediaPath, $replacementIndex);
                $replacementIndex++;
                $replacedCount++;

                if ($quote === '') {
                    return sprintf('{{media url=%s}}', $fallbackPath);
                }

                return sprintf('{{media url=%s%s%s}}', $quote, $fallbackPath, $quote);
            },
            $content
        );

        return [
            'content' => $repairedContent,
            'replaced' => $replacedCount,
        ];
    }

    private function shouldRepairReference(string $identifier, string $mediaPath): bool
    {
        if (in_array($identifier, self::TARGET_BLOCK_IDENTIFIERS, true)) {
            return true;
        }

        $normalizedPath = strtolower($mediaPath);
        if (str_starts_with($normalizedPath, self::HOME_BANNERS_PREFIX)) {
            return true;
        }

        if (str_contains($normalizedPath, '/')) {
            return false;
        }

        $extension = strtolower((string) pathinfo($normalizedPath, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::LEGACY_IMAGE_EXTENSIONS, true);
    }

    private function resolveFallbackPath(string $identifier, string $missingMediaPath, int $replacementIndex): string
    {
        if ($identifier === 'shipping_support_fashion_2') {
            return self::ICON_FALLBACK;
        }

        $basename = strtolower((string) pathinfo($missingMediaPath, PATHINFO_BASENAME));

        if (str_contains($basename, 'shipping')
            || str_contains($basename, 'help')
            || str_contains($basename, 'payment')
            || str_contains($basename, 'icon_')
        ) {
            return self::ICON_FALLBACK;
        }

        return self::BANNER_FALLBACKS[$replacementIndex % count(self::BANNER_FALLBACKS)];
    }

    private function mediaFileExists(string $mediaPath): bool
    {
        $mediaRoot = $this->directoryList->getPath(DirectoryList::MEDIA);

        return is_file($mediaRoot . '/' . ltrim($mediaPath, '/'));
    }

    private function normalizeMediaPath(string $mediaPath): string
    {
        $decodedPath = html_entity_decode($mediaPath, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decodedPath = trim($decodedPath);
        $decodedPath = trim($decodedPath, "\"'");

        return ltrim($decodedPath, '/');
    }
}
