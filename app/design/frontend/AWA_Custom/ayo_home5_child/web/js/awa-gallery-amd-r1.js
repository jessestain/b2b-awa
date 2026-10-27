/* Ensure the plugin is bound to Magento's AMD jQuery before gallery construction. */
define(['js/awa-fotorama-amd-r1', 'mage/gallery/gallery'], function (plugin, Gallery) {
    'use strict';
    return Gallery;
});
