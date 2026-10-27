<?php
/**
 * S1-P2: handler do cron.log com nível WARNING+ (silencia o ruído INFO de 5-8MB/dia).
 * Subclasse de Base — o nível do handler em 2.4.8 vem da propriedade $loggerType
 * (o construtor de Base não aceita argumento "level"; por isso overrides por DI não funcionam).
 */
declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Logger\Handler;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class CronWarn extends Base
{
    /**
     * @var int
     */
    protected $loggerType = Logger::WARNING;
}
