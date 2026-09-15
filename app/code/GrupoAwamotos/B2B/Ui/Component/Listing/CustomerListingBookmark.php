<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Ui\Component\Listing;

use Magento\Ui\Component\Bookmark;

/**
 * Saved grid views keep b2b_cnpj visible=true. Hide it without writing ui_bookmark.
 */
class CustomerListingBookmark extends Bookmark
{
    /**
     * @inheritdoc
     */
    public function prepare(): void
    {
        parent::prepare();
        $config = (array) $this->getData('config');
        $config = HideDuplicateCnpjColumn::inBookmarkConfig($config);
        $this->setData('config', $config);
    }
}
