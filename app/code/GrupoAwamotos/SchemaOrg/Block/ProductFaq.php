<?php

/**
 * Product FAQ Schema.org Block
 * Gera markup JSON-LD FAQPage para produtos.
 * As perguntas podem vir de:
 *   1. Atributo custom do produto (awa_faq_json)
 *   2. Defaults sensatos por categoria (frete, garantia, troca)
 */

declare(strict_types=1);

namespace GrupoAwamotos\SchemaOrg\Block;

use Magento\Catalog\Model\Product;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class ProductFaq extends Template
{
    protected Registry $registry;
    protected StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        Registry $registry,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    public function getProduct(): ?Product
    {
        $product = $this->registry->registry('current_product');
        return $product instanceof Product ? $product : null;
    }

    /**
     * Retorna JSON-LD FAQPage pronto para <script type="application/ld+json">.
     * Retorna string vazia se não houver produto.
     */
    public function getFaqSchemaJson(): string
    {
        $product = $this->getProduct();
        if (!$product) {
            return '';
        }

        $faqs = $this->resolveFaqs($product);
        if ($faqs === []) {
            return '';
        }

        $payload = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static function (array $faq): array {
                return [
                    '@type' => 'Question',
                    'name' => $faq['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $faq['answer'],
                    ],
                ];
            }, $faqs),
        ];

        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    /**
     * Retorna array de FAQs para uso tanto no schema JSON-LD quanto na renderização HTML.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public function getFaqs(): array
    {
        $product = $this->getProduct();
        if (!$product) {
            return [];
        }

        return $this->resolveFaqs($product);
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    private function resolveFaqs(Product $product): array
    {
        $custom = trim((string) $product->getData('awa_faq_json'));
        if ($custom !== '') {
            $decoded = json_decode($custom, true);
            if (is_array($decoded) && $this->isValidFaqList($decoded)) {
                return $decoded;
            }
        }

        return $this->getDefaultFaqs($product);
    }

    /**
     * FAQs default aplicáveis a qualquer produto de autopeças:
     *  - prazo de entrega
     *  - troca/devolução
     *  - garantia
     *  - formas de pagamento
     *  - nota fiscal
     *
     * @return array<int, array{question: string, answer: string}>
     */
    private function getDefaultFaqs(Product $product): array
    {
        $storeName = $this->storeManager->getStore()->getName() ?: 'AWA Motos';

        return [
            [
                'question' => 'Qual é o prazo de entrega?',
                'answer' => sprintf(
                    'O prazo de entrega varia de acordo com o CEP de destino e a forma de envio escolhida. Após a confirmação do pagamento, você recebe o código de rastreamento por e-mail. Em geral, entregas para todo o Brasil ocorrem entre 2 e 10 dias úteis.',
                    $storeName
                ),
            ],
            [
                'question' => 'Posso trocar ou devolver o produto?',
                'answer' => sprintf(
                    'Sim. Você tem até 7 dias corridos após o recebimento para solicitar troca ou devolução, conforme o Código de Defesa do Consumidor. O produto deve estar na embalagem original, sem indícios de uso e com todos os acessórios. Entre em contato com nosso atendimento para iniciar o processo.',
                    $storeName
                ),
            ],
            [
                'question' => 'O produto tem garantia?',
                'answer' => sprintf(
                    'Todos os produtos comercializados pela %s contam com garantia legal do fabricante. Em caso de defeito de fabricação dentro do prazo, entre em contato para análise técnica e providências.',
                    $storeName
                ),
            ],
            [
                'question' => 'Quais formas de pagamento são aceitas?',
                'answer' => 'O pagamento é a combinar para todos os pedidos. A forma e as condições de pagamento serão acordadas com nossa equipe comercial.',
            ],
            [
                'question' => 'A nota fiscal é emitida?',
                'answer' => sprintf(
                    'Sim. Emitimos nota fiscal eletrônica (NF-e) para todas as vendas. Para CNPJ, a NF-e é emitida com os dados cadastrais da empresa. Para CPF, segue para pessoa física.',
                    $storeName
                ),
            ],
        ];
    }

    /**
     * @param mixed $list
     */
    private function isValidFaqList($list): bool
    {
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $entry) {
            if (!is_array($entry) || !isset($entry['question'], $entry['answer'])) {
                return false;
            }
            if (!is_string($entry['question']) || !is_string($entry['answer'])) {
                return false;
            }
            if (trim($entry['question']) === '' || trim($entry['answer']) === '') {
                return false;
            }
        }
        return $list !== [];
    }
}
