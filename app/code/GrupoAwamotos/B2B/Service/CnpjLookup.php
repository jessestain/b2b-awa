<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Consulta dados cadastrais de CNPJ via API pública ReceitaWS.
 *
 * API: https://www.receitaws.com.br/v1/cnpj/{cnpj}
 * Gratuita para uso razoável (~1 req/min por IP na versão free).
 * Retorna razão social, nome fantasia, endereço, situação cadastral.
 */
class CnpjLookup
{
    private const API_URL      = 'https://www.receitaws.com.br/v1/cnpj/%s';
    private const TIMEOUT_SEC  = 8;
    private const CACHE_KEY_PREFIX = 'grupoawamotos_b2b_receita_lookup_';
    private const CACHE_TAG = 'GRUPOAWAMOTOS_B2B_CNPJ_LOOKUP';
    private const CACHE_TTL = 3600;

    private array $cache = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cacheStorage,
        private readonly Json $jsonSerializer
    ) {
    }

    /**
     * Busca dados do CNPJ na Receita Federal.
     *
     * @return array{
     *   nome: string,
     *   fantasia: string,
     *   situacao: string,
     *   logradouro: string,
     *   numero: string,
     *   complemento: string,
     *   bairro: string,
     *   municipio: string,
     *   uf: string,
     *   cep: string,
     *   telefone: string,
     *   email: string,
     *   abertura: string
     * }|null  null se CNPJ inválido, não encontrado ou API indisponível.
     */
    public function lookup(string $cnpj): ?array
    {
        $clean = preg_replace('/[^0-9]/', '', $cnpj);

        if (strlen($clean) !== 14) {
            return null;
        }

        if (array_key_exists($clean, $this->cache)) {
            return $this->cache[$clean];
        }

        $cached = $this->loadFromPersistentCache($clean);
        if ($cached['hit']) {
            $this->cache[$clean] = $cached['result'];
            return $cached['result'];
        }

        try {
            $url  = sprintf(self::API_URL, $clean);
            $body = $this->httpGet($url);

            if (!$body) {
                $this->cache[$clean] = null;
                $this->saveToPersistentCache($clean, null);
                return null;
            }

            $data = json_decode($body, true);

            if (empty($data) || ($data['status'] ?? '') === 'ERROR') {
                $this->logger->debug(
                    '[B2B CnpjLookup] CNPJ ' . $this->maskCnpj($clean) . ' não encontrado: ' . ($data['message'] ?? '')
                );
                $this->cache[$clean] = null;
                $this->saveToPersistentCache($clean, null);
                return null;
            }

            // Normaliza CEP
            if (isset($data['cep'])) {
                $data['cep'] = preg_replace('/[^0-9]/', '', $data['cep']);
            }

            $this->cache[$clean] = $data;
            $this->saveToPersistentCache($clean, $data);
            return $data;
        } catch (\Exception $e) {
            $this->logger->debug(
                '[B2B CnpjLookup] Falha para CNPJ ' . $this->maskCnpj($clean) . ': ' . $e->getMessage()
            );
            return null;
        }
    }

    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: AWA-Motos/1.0 (+https://awamotos.com)',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $body  = curl_exec($ch);
        $error = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $body === false) {
            throw new \RuntimeException($error ?: 'cURL returned false');
        }

        // Rate limit ou erro de servidor
        if ($code === 429 || $code >= 500) {
            throw new \RuntimeException('ReceitaWS returned HTTP ' . $code);
        }

        return $body ?: null;
    }

    /**
     * @return array{hit: bool, result: ?array}
     */
    private function loadFromPersistentCache(string $cnpj): array
    {
        try {
            $raw = $this->cacheStorage->load($this->buildCacheId($cnpj));
            if ($raw === false || $raw === '') {
                return ['hit' => false, 'result' => null];
            }

            $decoded = $this->jsonSerializer->unserialize($raw);
            if (!is_array($decoded) || !array_key_exists('result', $decoded)) {
                return ['hit' => false, 'result' => null];
            }

            $result = $decoded['result'];
            return [
                'hit' => true,
                'result' => is_array($result) ? $result : null,
            ];
        } catch (\Throwable) {
            return ['hit' => false, 'result' => null];
        }
    }

    private function saveToPersistentCache(string $cnpj, ?array $result): void
    {
        try {
            $payload = $this->jsonSerializer->serialize(['result' => $result]);
            $this->cacheStorage->save(
                $payload,
                $this->buildCacheId($cnpj),
                [self::CACHE_TAG],
                self::CACHE_TTL
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[CnpjLookup::saveToPersistentCache] Exceção ignorada: ' . $e->getMessage());
            // Best-effort cache; never break lookup flow.
        }
    }

    private function buildCacheId(string $cnpj): string
    {
        return self::CACHE_KEY_PREFIX . sha1($cnpj);
    }

    private function maskCnpj(string $cnpj): string
    {
        $digits = preg_replace('/\D/', '', $cnpj);
        if (strlen($digits) !== 14) {
            return '***';
        }

        return substr($digits, 0, 2) . '********' . substr($digits, -4);
    }
}
