<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Helper;

use GrupoAwamotos\B2B\Helper\CnpjValidator;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \GrupoAwamotos\B2B\Helper\CnpjValidator
 */
class CnpjValidatorTest extends TestCase
{
    private CnpjValidator $validator;
    private Context&MockObject $context;
    private Curl&MockObject $curl;
    private CacheInterface&MockObject $cache;
    private Json&MockObject $json;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->context = $this->createMock(Context::class);
        $this->context->method('getScopeConfig')->willReturn($this->scopeConfig);
        $this->context->method('getLogger')->willReturn($this->logger);

        $this->curl = $this->createMock(Curl::class);
        $this->cache = $this->createMock(CacheInterface::class);

        // Json mock: serialize always returns valid JSON string for audit logging
        $this->json = $this->createMock(Json::class);
        $this->json->method('serialize')->willReturnCallback(
            fn ($value) => json_encode($value) ?: '{}'
        );

        $this->validator = new CnpjValidator(
            $this->context,
            $this->curl,
            $this->cache,
            $this->json
        );
    }

    // ====================================================================
    // validateLocal — algoritmo puro de validação CNPJ
    // ====================================================================

    public function testValidateLocalWithValidCnpj(): void
    {
        $this->assertTrue($this->validator->validateLocal('11222333000181'));
    }

    public function testValidateLocalWithFormattedValidCnpj(): void
    {
        $this->assertTrue($this->validator->validateLocal('11.222.333/0001-81'));
    }

    public function testValidateLocalWithAnotherValidCnpj(): void
    {
        // 27.865.757/0001-02
        $this->assertTrue($this->validator->validateLocal('27865757000102'));
    }

    public function testValidateLocalWithThirdValidCnpj(): void
    {
        // Petrobras: 33.000.167/0001-01
        $this->assertTrue($this->validator->validateLocal('33000167000101'));
    }

    public function testValidateLocalWithInvalidCnpj(): void
    {
        $this->assertFalse($this->validator->validateLocal('11222333000100'));
    }

    public function testValidateLocalRejectsAllZeros(): void
    {
        $this->assertFalse($this->validator->validateLocal('00000000000000'));
    }

    public function testValidateLocalRejectsAllOnes(): void
    {
        $this->assertFalse($this->validator->validateLocal('11111111111111'));
    }

    public function testValidateLocalRejectsAllNines(): void
    {
        $this->assertFalse($this->validator->validateLocal('99999999999999'));
    }

    public function testValidateLocalRejectsAllFives(): void
    {
        $this->assertFalse($this->validator->validateLocal('55555555555555'));
    }

    public function testValidateLocalRejectsShortCnpj(): void
    {
        $this->assertFalse($this->validator->validateLocal('1122233300018'));
    }

    public function testValidateLocalRejectsLongCnpj(): void
    {
        $this->assertFalse($this->validator->validateLocal('112223330001811'));
    }

    public function testValidateLocalRejectsEmptyString(): void
    {
        $this->assertFalse($this->validator->validateLocal(''));
    }

    public function testValidateLocalRejectsLetters(): void
    {
        $this->assertFalse($this->validator->validateLocal('abcdefghijklmn'));
    }

    public function testValidateLocalWithWrongFirstVerifierDigit(): void
    {
        // Last two digits of valid 11222333000181 changed: first verifier wrong
        $this->assertFalse($this->validator->validateLocal('11222333000191'));
    }

    public function testValidateLocalWithWrongSecondVerifierDigit(): void
    {
        // Second verifier digit wrong
        $this->assertFalse($this->validator->validateLocal('11222333000182'));
    }

    public function testValidateLocalWithOnlySpaces(): void
    {
        $this->assertFalse($this->validator->validateLocal('   '));
    }

    public function testValidateLocalWithSpecialChars(): void
    {
        $this->assertFalse($this->validator->validateLocal('!@#$%^&*()+='));
    }

    // ====================================================================
    // clean
    // ====================================================================

    public function testCleanRemovesFormatting(): void
    {
        $this->assertSame('11222333000181', $this->validator->clean('11.222.333/0001-81'));
    }

    public function testCleanRemovesSlashAndDash(): void
    {
        $this->assertSame('11222333000181', $this->validator->clean('11222333/0001-81'));
    }

    public function testCleanKeepsDigitsOnly(): void
    {
        $this->assertSame('12345', $this->validator->clean('abc12345xyz'));
    }

    public function testCleanReturnsEmptyForNoDigits(): void
    {
        $this->assertSame('', $this->validator->clean('abcdef'));
    }

    public function testCleanReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', $this->validator->clean(''));
    }

    // ====================================================================
    // format
    // ====================================================================

    public function testFormatValidCnpj(): void
    {
        $this->assertSame('11.222.333/0001-81', $this->validator->format('11222333000181'));
    }

    public function testFormatAlreadyFormattedCnpj(): void
    {
        $this->assertSame('11.222.333/0001-81', $this->validator->format('11.222.333/0001-81'));
    }

    public function testFormatReturnsCleanedInputIfNotFourteenDigits(): void
    {
        // clean('12345') = '12345', not 14 digits → returns '12345'
        $this->assertSame('12345', $this->validator->format('12345'));
    }

    public function testFormatReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', $this->validator->format(''));
    }

    // ====================================================================
    // validateApi — retorna null para CNPJ localmente inválido
    // ====================================================================

    public function testValidateApiReturnsNullForInvalidCnpj(): void
    {
        $this->assertNull($this->validator->validateApi('00000000000000'));
    }

    public function testValidateApiReturnsNullForShortCnpj(): void
    {
        $this->assertNull($this->validator->validateApi('123'));
    }

    // ====================================================================
    // validateApi — lookup desabilitado → fallback local
    // ====================================================================

    public function testValidateApiReturnsFallbackWhenLookupDisabled(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $result = $this->validator->validateApi('11222333000181');

        $this->assertNotNull($result);
        $this->assertTrue($result['valid']);
        $this->assertSame('fallback', $result['source']);
        $this->assertTrue($result['api_error']);
    }

    // ====================================================================
    // validateApi — cache hit
    // ====================================================================

    public function testValidateApiReturnsCachedResult(): void
    {
        $cachedData = ['valid' => true, 'source' => 'api', 'razao_social' => 'Empresa Teste'];

        $this->scopeConfig->method('isSetFlag')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'enabled') => true,
                str_contains($path, 'cache_enabled') => true,
                default => false,
            };
        });

        $this->cache->method('load')
            ->with('grupoawamotos_b2b_cnpj_lookup_11222333000181')
            ->willReturn('{"valid":true}');

        $this->json->method('unserialize')
            ->with('{"valid":true}')
            ->willReturn($cachedData);

        $result = $this->validator->validateApi('11222333000181');

        $this->assertNotNull($result);
        $this->assertTrue($result['valid']);
        $this->assertSame('api', $result['source']);
        $this->assertTrue($result['cached']);
    }

    // ====================================================================
    // validateApi — chamada API com sucesso
    // ====================================================================

    public function testValidateApiCallsApiAndReturnsPayload(): void
    {
        $apiResponse = json_encode([
            'nome' => 'AWA Motos LTDA',
            'fantasia' => 'AWA Motos',
            'cnpj' => '11222333000181',
            'situacao' => 'ATIVA',
            'tipo' => 'MATRIZ',
            'porte' => 'PEQUENO',
            'natureza_juridica' => 'Sociedade Limitada',
            'atividade_principal' => [['text' => 'Comércio de peças']],
            'logradouro' => 'Rua Teste',
            'numero' => '100',
            'complemento' => '',
            'bairro' => 'Centro',
            'municipio' => 'Araraquara',
            'uf' => 'SP',
            'cep' => '14800000',
            'telefone' => '(16) 3333-3333',
            'email' => 'awamotos@awamotos.com.br'
        ]);

        $this->scopeConfig->method('isSetFlag')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'enabled') => true,
                str_contains($path, 'cache_enabled') => false,
                str_contains($path, 'require_active') => false,
                default => false,
            };
        });

        $this->scopeConfig->method('getValue')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'api_url') => 'https://receitaws.com.br/v1/cnpj/',
                str_contains($path, 'timeout') => '10',
                default => null,
            };
        });

        $this->curl->method('getBody')->willReturn($apiResponse);

        $result = $this->validator->validateApi('11222333000181');

        $this->assertNotNull($result);
        $this->assertTrue($result['valid']);
        $this->assertSame('api', $result['source']);
        $this->assertSame('AWA Motos LTDA', $result['razao_social']);
        $this->assertSame('AWA Motos', $result['nome_fantasia']);
        $this->assertSame('SP', $result['uf']);
        $this->assertSame('Comércio de peças', $result['atividade_principal']);
    }

    // ====================================================================
    // validateApi — exception na API sem fallback → null
    // ====================================================================

    public function testValidateApiReturnsNullOnApiExceptionWithoutFallback(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'enabled') => true,
                str_contains($path, 'cache_enabled') => false,
                str_contains($path, 'allow_local_fallback') => false,
                default => false,
            };
        });

        $this->scopeConfig->method('getValue')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'api_url') => 'https://receitaws.com.br/v1/cnpj/',
                str_contains($path, 'timeout') => '10',
                default => null,
            };
        });

        $this->curl->method('get')->willThrowException(new \Exception('Connection timeout'));

        $result = $this->validator->validateApi('11222333000181');
        $this->assertNull($result);
    }

    // ====================================================================
    // validateApi — exception na API com fallback → local payload
    // ====================================================================

    public function testValidateApiReturnsFallbackOnApiExceptionWithFallback(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'enabled') => true,
                str_contains($path, 'cache_enabled') => false,
                str_contains($path, 'allow_local_fallback') => true,
                default => false,
            };
        });

        $this->scopeConfig->method('getValue')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'api_url') => 'https://receitaws.com.br/v1/cnpj/',
                str_contains($path, 'timeout') => '10',
                default => null,
            };
        });

        $this->curl->method('get')->willThrowException(new \Exception('API down'));

        $result = $this->validator->validateApi('11222333000181');

        $this->assertNotNull($result);
        $this->assertTrue($result['valid']);
        $this->assertSame('fallback', $result['source']);
        $this->assertTrue($result['api_error']);
    }

    // ====================================================================
    // validateApi — API retorna ERROR status → null
    // ====================================================================

    public function testValidateApiReturnsNullWhenApiReturnsErrorStatus(): void
    {
        $apiResponse = json_encode(['status' => 'ERROR', 'message' => 'CNPJ inválido']);

        $this->scopeConfig->method('isSetFlag')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'enabled') => true,
                str_contains($path, 'cache_enabled') => false,
                default => false,
            };
        });

        $this->scopeConfig->method('getValue')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'api_url') => 'https://receitaws.com.br/v1/cnpj/',
                str_contains($path, 'timeout') => '10',
                default => null,
            };
        });

        $this->curl->method('getBody')->willReturn($apiResponse);

        $result = $this->validator->validateApi('11222333000181');
        $this->assertNull($result);
    }

    public function testValidateApiFallsBackLocallyWhenReceitaReturnsError(): void
    {
        $apiResponse = json_encode(['status' => 'ERROR', 'message' => 'CNPJ rejeitado pela Receita Federal']);

        $this->scopeConfig->method('isSetFlag')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'allow_local_fallback') => true,
                str_contains($path, 'cache_enabled') => false,
                str_contains($path, 'enabled') => true,
                default => false,
            };
        });

        $this->scopeConfig->method('getValue')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'api_url') => 'https://receitaws.com.br/v1/cnpj/',
                str_contains($path, 'timeout') => '10',
                default => null,
            };
        });

        $this->stubLookupHttp([
            'receitaws' => ['status' => 200, 'body' => $apiResponse ?: '{}'],
            'brasilapi' => ['status' => 404, 'body' => '{"message":"not found"}'],
            'cnpj.ws' => ['status' => 404, 'body' => '{"error":"not found"}'],
        ]);

        $result = $this->validator->validateApi('11222333000181');

        $this->assertNotNull($result);
        $this->assertTrue($result['valid']);
        $this->assertSame('fallback', $result['source']);
        $this->assertTrue($result['api_error']);
        $this->assertStringContainsString('bases públicas da Receita', (string) $result['message']);
    }

    public function testValidateApiUsesCnpjWsWhenReceitaAndBrasilApiMiss(): void
    {
        $cnpjWs = json_encode([
            'razao_social' => 'Empresa Nova LTDA',
            'porte' => ['descricao' => 'Micro Empresa'],
            'natureza_juridica' => ['descricao' => 'Sociedade Empresária Limitada'],
            'estabelecimento' => [
                'tipo' => 'Matriz',
                'nome_fantasia' => 'Empresa Nova',
                'situacao_cadastral' => 'Ativa',
                'tipo_logradouro' => 'Rua',
                'logradouro' => 'Central',
                'numero' => '10',
                'complemento' => '',
                'bairro' => 'Centro',
                'cep' => '14800000',
                'ddd1' => '16',
                'telefone1' => '33333333',
                'email' => 'contato@example.com',
                'cidade' => ['nome' => 'Araraquara'],
                'estado' => ['sigla' => 'SP'],
                'atividade_principal' => [
                    'id' => '4541206',
                    'subclasse' => '4541-2/06',
                    'descricao' => 'Comércio a varejo de peças',
                ],
            ],
        ]);

        $this->scopeConfig->method('isSetFlag')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'allow_local_fallback') => false,
                str_contains($path, 'require_active') => true,
                str_contains($path, 'cache_enabled') => false,
                str_contains($path, 'enabled') => true,
                default => false,
            };
        });

        $this->scopeConfig->method('getValue')->willReturnCallback(function (string $path) {
            return match (true) {
                str_contains($path, 'api_url') => 'https://receitaws.com.br/v1/cnpj/',
                str_contains($path, 'timeout') => '10',
                default => null,
            };
        });

        $this->stubLookupHttp([
            'receitaws' => [
                'status' => 200,
                'body' => json_encode(['status' => 'ERROR', 'message' => 'CNPJ rejeitado pela Receita Federal']) ?: '{}',
            ],
            'brasilapi' => ['status' => 404, 'body' => '{"message":"not found"}'],
            'cnpj.ws' => ['status' => 200, 'body' => $cnpjWs ?: '{}'],
        ]);

        $result = $this->validator->validateApi('11222333000181');

        $this->assertNotNull($result);
        $this->assertTrue($result['valid']);
        $this->assertSame('cnpjws', $result['source']);
        $this->assertSame('Empresa Nova LTDA', $result['razao_social']);
        $this->assertSame('Empresa Nova', $result['nome_fantasia']);
        $this->assertSame('ATIVA', $result['situacao']);
        $this->assertSame('SP', $result['uf']);
        $this->assertSame('Araraquara', $result['municipio']);
        $this->assertSame('14800000', $result['cep']);
        $this->assertSame('1633333333', $result['telefone']);
        $this->assertSame('Comércio a varejo de peças', $result['atividade_principal']);
    }

    /**
     * @param array<string, array{status: int, body: string}> $responsesByNeedle
     */
    private function stubLookupHttp(array $responsesByNeedle): void
    {
        $lastUrl = new \stdClass();
        $lastUrl->value = '';

        $this->curl->method('get')->willReturnCallback(function ($uri) use ($lastUrl) {
            $lastUrl->value = (string) $uri;
        });
        $this->curl->method('getBody')->willReturnCallback(function () use ($lastUrl, $responsesByNeedle) {
            foreach ($responsesByNeedle as $needle => $payload) {
                if (str_contains($lastUrl->value, $needle)) {
                    return $payload['body'];
                }
            }

            return '{}';
        });
        $this->curl->method('getStatus')->willReturnCallback(function () use ($lastUrl, $responsesByNeedle) {
            foreach ($responsesByNeedle as $needle => $payload) {
                if (str_contains($lastUrl->value, $needle)) {
                    return $payload['status'];
                }
            }

            return 0;
        });
    }

    // ====================================================================
    // clearCache
    // ====================================================================

    public function testClearCacheSingleCnpj(): void
    {
        $this->cache->expects($this->once())
            ->method('remove')
            ->with('grupoawamotos_b2b_cnpj_lookup_11222333000181')
            ->willReturn(true);

        $this->assertTrue($this->validator->clearCache('11.222.333/0001-81'));
    }

    public function testClearCacheAllCnpjs(): void
    {
        $this->cache->expects($this->once())
            ->method('clean')
            ->with(['GRUPOAWAMOTOS_B2B_CNPJ_LOOKUP'])
            ->willReturn(true);

        $this->assertTrue($this->validator->clearCache());
    }

    public function testClearCacheReturnsFalseForInvalidCnpj(): void
    {
        $this->assertFalse($this->validator->clearCache('123'));
    }

    public function testClearCacheReturnsFalseForShortCnpj(): void
    {
        $this->assertFalse($this->validator->clearCache('1234567890'));
    }

    // ====================================================================
    // Config getters
    // ====================================================================

    public function testIsRateLimitEnabledReturnsTrue(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willReturnCallback(function (string $path) {
                return str_contains($path, 'rate_limit_enabled');
            });

        $this->assertTrue($this->validator->isRateLimitEnabled());
    }

    public function testIsRateLimitEnabledReturnsFalse(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->assertFalse($this->validator->isRateLimitEnabled());
    }

    public function testGetRateLimitMaxRequestsReturnsConfigValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(function (string $path) {
            return str_contains($path, 'rate_limit_max_requests') ? '30' : null;
        });

        $this->assertSame(30, $this->validator->getRateLimitMaxRequests());
    }

    public function testGetRateLimitMaxRequestsReturnsDefault20(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->assertSame(20, $this->validator->getRateLimitMaxRequests());
    }

    public function testGetRateLimitMaxRequestsReturnsDefaultForZero(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');
        $this->assertSame(20, $this->validator->getRateLimitMaxRequests());
    }

    public function testGetRateLimitWindowSecondsReturnsConfigValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(function (string $path) {
            return str_contains($path, 'rate_limit_window_seconds') ? '120' : null;
        });

        $this->assertSame(120, $this->validator->getRateLimitWindowSeconds());
    }

    public function testGetRateLimitWindowSecondsReturnsDefault60(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->assertSame(60, $this->validator->getRateLimitWindowSeconds());
    }

    public function testGetRateLimitWindowSecondsReturnsDefaultForZero(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');
        $this->assertSame(60, $this->validator->getRateLimitWindowSeconds());
    }
}
