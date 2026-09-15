<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Registration;

use Magento\Framework\App\RequestInterface;

/**
 * Normalises UTM/CNAME/vendedor params from the registration POST, GET or landing URL.
 */
class AttributionResolver
{
    private const MAX_LENGTH = 255;

    /** @var list<string> */
    private const MERGE_KEYS = [
        'cname',
        'campaign',
        'b2b_registration_campaign',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'registration_landing',
        'attendant',
        'attendant_id',
        'vendedor',
        'erp_seller_code',
    ];

    /**
     * @param array<string, mixed> $params
     */
    public function fromParams(array $params): AttributionData
    {
        $fromLanding = $this->queryParamsFromUrl((string) ($params['registration_landing'] ?? ''));
        $params = array_merge($fromLanding, $params);

        $campaign = $this->sanitize(
            (string) ($params['cname'] ?? $params['campaign'] ?? $params['b2b_registration_campaign'] ?? '')
        );
        $attendantId = $this->parsePositiveInt($params['attendant'] ?? $params['attendant_id'] ?? null);
        $erpSellerCode = $this->parsePositiveInt($params['vendedor'] ?? $params['erp_seller_code'] ?? null);

        return new AttributionData(
            campaignName: $campaign,
            utmSource: $this->sanitize((string) ($params['utm_source'] ?? '')),
            utmMedium: $this->sanitize((string) ($params['utm_medium'] ?? '')),
            utmCampaign: $this->sanitize((string) ($params['utm_campaign'] ?? '')),
            utmContent: $this->sanitize((string) ($params['utm_content'] ?? '')),
            utmTerm: $this->sanitize((string) ($params['utm_term'] ?? '')),
            landingPath: $this->sanitize((string) ($params['registration_landing'] ?? ''), 512),
            attendantId: $attendantId,
            erpSellerCode: $erpSellerCode
        );
    }

    public function fromRequest(RequestInterface $request): AttributionData
    {
        return $this->fromParams($request->getParams());
    }

    /**
     * First-touch: values already stored win over later empty or colliding params.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    public function mergeFirstTouch(array $stored, array $incoming): array
    {
        $merged = [];
        foreach (self::MERGE_KEYS as $key) {
            $previous = trim((string) ($stored[$key] ?? ''));
            $next = trim((string) ($incoming[$key] ?? ''));
            $merged[$key] = $previous !== '' ? $previous : $next;
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParamsFromUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return [];
        }

        $parsed = [];
        parse_str($query, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    private function sanitize(string $value, int $maxLength = self::MAX_LENGTH): string
    {
        $value = trim(strip_tags($value));
        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) preg_replace('/\D/', '', (string) $value);

        return $int > 0 ? $int : null;
    }
}
