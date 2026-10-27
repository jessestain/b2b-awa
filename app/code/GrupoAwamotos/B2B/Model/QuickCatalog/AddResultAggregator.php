<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

/**
 * Agregador puro de resultados por item (sucesso total, parcial ou falha).
 *
 * Não inventa resultado: added/errors são exatamente o que os serviços
 * informaram por SKU. A resposta final inclui contadores derivados.
 */
class AddResultAggregator
{
    /** @var array<int, array{sku:string,qty:int,name:string}> */
    private array $added = [];

    /** @var array<int, array{sku:string,message:string}> */
    private array $errors = [];

    /** @var int contador de itens processados no lote */
    private int $processed = 0;

    /**
     * Contabiliza um item processado no lote.
     *
     * @param string $sku
     */
    public function startItem(string $sku): void
    {
        $this->processed++;
    }

    /**
     * Registra um item adicionado com sucesso ao carrinho.
     *
     * @param string $sku
     * @param int $qty
     * @param string $name
     * @param int $cartQtySku
     */
    public function addSuccess(string $sku, int $qty, string $name, int $cartQtySku): void
    {
        $this->added[] = [
            'sku' => $sku,
            'qty' => $qty,
            'name' => $name,
            'cart_qty_sku' => $cartQtySku,
        ];
    }

    /**
     * Registra a falha de um item com a mensagem informada.
     *
     * @param string $sku
     * @param string $message
     */
    public function addError(string $sku, string $message): void
    {
        $this->errors[] = [
            'sku' => $sku,
            'message' => $message,
        ];
    }

    /**
     * Indica se ao menos um item foi adicionado com sucesso.
     *
     * @return bool
     */
    public function hasAdded(): bool
    {
        return $this->added !== [];
    }

    /**
     * Devolve o resultado agregado do lote no formato de resposta.
     *
     * @return array{added:array,errors:array,processed:int,summary:array}
     */
    public function toArray(): array
    {
        return [
            'added' => $this->added,
            'errors' => $this->errors,
            'processed' => $this->processed,
            'summary' => [
                'added' => count($this->added),
                'failed' => count($this->errors),
            ],
        ];
    }
}
