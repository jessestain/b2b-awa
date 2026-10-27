<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Guide;

/**
 * Deterministic purchase guide for lay users.
 *
 * Magento owns slots (part → bike → year). Hermes only talks once the
 * catalog tools have facts, or when the visitor leaves the guide.
 */
final class SlotGuide
{
    public const STEP_PART = 'part';
    public const STEP_BIKE = 'bike';
    public const STEP_YEAR = 'year';
    public const STEP_RESULTS = 'results';

    public const START_REPLY = 'Vou te guiar em 3 passos. Qual peça você precisa? '
        . 'Toque numa opção ou escreva o nome.';

    private const MAX_SLOT = 80;

    /**
     * @param string $whatsappLabel WhatsApp de atendimento já formatado — vem da
     *        config (Orchestrator::whatsappLabel). Antes era "(16) 3301-1890",
     *        que é o telefone fixo, não o WhatsApp.
     */
    public function __construct(
        private readonly string $whatsappLabel = '(16) 99736-7588'
    ) {
    }

    /**
     * @return array{
     *     active: bool,
     *     step: string,
     *     part: string,
     *     brand: string,
     *     model: string,
     *     year: string,
     *     skipped_part: bool
     * }
     */
    public function idle(): array
    {
        return [
            'active' => false,
            'step' => self::STEP_PART,
            'part' => '',
            'brand' => '',
            'model' => '',
            'year' => '',
            'skipped_part' => false,
        ];
    }

    /**
     * @return array{
     *     active: bool,
     *     step: string,
     *     part: string,
     *     brand: string,
     *     model: string,
     *     year: string,
     *     skipped_part: bool
     * }
     */
    public function start(): array
    {
        $state = $this->idle();
        $state['active'] = true;

        return $state;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{
     *     active: bool,
     *     step: string,
     *     part: string,
     *     brand: string,
     *     model: string,
     *     year: string,
     *     skipped_part: bool
     * }
     */
    public function sanitize(array $raw): array
    {
        $state = $this->idle();
        $state['active'] = !empty($raw['active']);
        $step = (string) ($raw['step'] ?? self::STEP_PART);
        $allowed = [self::STEP_PART, self::STEP_BIKE, self::STEP_YEAR, self::STEP_RESULTS];
        $state['step'] = in_array($step, $allowed, true) ? $step : self::STEP_PART;
        foreach (['part', 'brand', 'model', 'year'] as $key) {
            $state[$key] = $this->clip((string) ($raw[$key] ?? ''));
        }
        $state['skipped_part'] = !empty($raw['skipped_part']);

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $intent
     * @return array{
     *     exit: bool,
     *     ready: bool,
     *     reply: string,
     *     state: array<string, mixed>
     * }
     */
    public function advance(array $state, string $userMessage, array $intent): array
    {
        $state = $this->sanitize($state);
        $message = trim($userMessage);

        if ($message === '' || !$state['active']) {
            $state = $this->start();

            return [
                'exit' => false,
                'ready' => false,
                'reply' => self::START_REPLY,
                'state' => $state,
            ];
        }

        if ($this->isRestart($message)) {
            $state = $this->start();

            return [
                'exit' => false,
                'ready' => false,
                'reply' => self::START_REPLY,
                'state' => $state,
            ];
        }

        if ($this->isExit($message)) {
            return [
                'exit' => true,
                'ready' => false,
                'reply' => '',
                'state' => $this->idle(),
            ];
        }

        $state = $this->mergeIntent($state, $intent, $message);

        if ($state['part'] === '' && !$state['skipped_part']) {
            $state['step'] = self::STEP_PART;

            return $this->ask($state, 'Qual peça você precisa? Toque numa opção ou escreva o nome.');
        }

        if ($state['model'] === '') {
            $state['step'] = self::STEP_BIKE;
            $hint = $state['skipped_part']
                ? 'Sem problema. Qual é a moto?'
                : 'Certo. Qual é a moto?';

            return $this->ask($state, $hint);
        }

        if ($state['year'] === '') {
            $state['step'] = self::STEP_YEAR;
            $label = trim($state['part'] . ' · ' . $state['model']);

            return $this->ask(
                $state,
                ($label !== '·' ? $label . '. ' : '')
                . 'Qual o ano da moto? Se não souber, toque em Qualquer ano.'
            );
        }

        $state['step'] = self::STEP_RESULTS;

        return [
            'exit' => false,
            'ready' => true,
            'reply' => '',
            'state' => $state,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    public function intentFromState(array $state, array $intent): array
    {
        $state = $this->sanitize($state);
        $part = $state['part'];
        $brand = $state['brand'] !== '' ? $state['brand'] : (string) ($intent['brand'] ?? '');
        $model = $state['model'];
        $year = $state['year'] === 'qualquer' ? '' : $state['year'];

        $searchParts = array_filter([$part, $brand, $model, $year], static fn(string $v): bool => $v !== '');

        return [
            'is_product_query' => $part !== '' || $model !== '',
            'prefers_fitment' => $model !== '',
            'part' => $part,
            'brand' => $brand,
            'model' => $model,
            'year' => $year,
            'search_query' => trim(implode(' ', $searchParts)),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<int, array<string, mixed>> $products
     */
    public function composeFoundReply(array $state, array $products, bool $isB2b): string
    {
        $state = $this->sanitize($state);
        $count = count($products);
        $scope = trim($state['part'] . ' ' . $state['model']);
        $scope = trim($scope) !== '' ? ' para ' . trim($scope) : '';
        $lead = $count === 1
            ? 'Encontrei 1 opção' . $scope . ' no catálogo.'
            : 'Encontrei ' . $count . ' opções' . $scope . ' no catálogo.';
        $next = $isB2b
            ? 'Abra o card e confira a compatibilidade na ficha.'
            : 'Abra o card e confira a compatibilidade. Para ver preço, cadastre o CNPJ ou fale no WhatsApp.';

        return $lead . ' ' . $next;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function composeMissReply(array $state): string
    {
        $state = $this->sanitize($state);
        $scope = trim($state['part'] . ' ' . $state['model']);
        $scope = $scope !== '' ? ' de ' . $scope : '';

        return 'Não encontrei' . $scope . ' no catálogo agora. Tente outro nome ou fale no WhatsApp ' . $this->whatsappLabel . '.';
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function toClient(array $state, bool $isB2b = false): array
    {
        $state = $this->sanitize($state);
        $step = $state['active'] ? $state['step'] : self::STEP_PART;
        $progress = match ($step) {
            self::STEP_PART => 1,
            self::STEP_BIKE => 2,
            self::STEP_YEAR, self::STEP_RESULTS => 3,
            default => 1,
        };

        return [
            'active' => $state['active'],
            'step' => $state['step'],
            'part' => $state['part'],
            'brand' => $state['brand'],
            'model' => $state['model'],
            'year' => $state['year'],
            'skipped_part' => $state['skipped_part'],
            'progress' => $progress,
            'total' => 3,
            'chips' => $this->chipsFor($state, $isB2b),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return list<array{id: string, caption: string, value: string, action: string}>
     */
    public function chipsFor(array $state, bool $isB2b = false): array
    {
        $state = $this->sanitize($state);
        if (!$state['active']) {
            return [
                $this->chip('start', 'Me guia passo a passo', '', 'start_guide'),
            ];
        }

        return match ($state['step']) {
            self::STEP_PART => [
                $this->chip('retrovisor', 'Retrovisor', 'Retrovisor', 'send'),
                $this->chip('bauleto', 'Bauleto', 'Bauleto', 'send'),
                $this->chip('guidao', 'Guidão', 'Guidão', 'send'),
                $this->chip('freio', 'Freio', 'Freio', 'send'),
                $this->chip('manopla', 'Manopla', 'Manopla', 'send'),
                $this->chip('skip_part', 'Não sei o nome', 'Não sei o nome da peça', 'send'),
            ],
            self::STEP_BIKE => [
                $this->chip('titan150', 'Titan 150', 'Titan 150', 'send'),
                $this->chip('titan160', 'Titan 160', 'Titan 160', 'send'),
                $this->chip('biz125', 'Biz 125', 'Biz 125', 'send'),
                $this->chip('cg160', 'CG 160', 'CG 160', 'send'),
                $this->chip('fazer250', 'Fazer 250', 'Fazer 250', 'send'),
                $this->chip('bros160', 'Bros 160', 'Bros 160', 'send'),
                $this->chip('fan160', 'Fan 160', 'Fan 160', 'send'),
            ],
            self::STEP_YEAR => [
                $this->chip('any_year', 'Qualquer ano', 'Qualquer ano', 'send'),
                $this->chip('y2024', '2024', '2024', 'send'),
                $this->chip('y2022', '2022', '2022', 'send'),
                $this->chip('y2020', '2020', '2020', 'send'),
                $this->chip('y2018', '2018', '2018', 'send'),
                $this->chip('y2015', '2015', '2015', 'send'),
            ],
            self::STEP_RESULTS => $this->resultChips($isB2b),
            default => [],
        };
    }

    /**
     * @return list<array{id: string, caption: string, value: string, action: string}>
     */
    private function resultChips(bool $isB2b): array
    {
        $chips = [
            $this->chip('restart', 'Outra peça', 'Outra peça', 'send'),
            $this->chip('handoff', 'Falar no WhatsApp', '', 'handoff'),
        ];
        if (!$isB2b) {
            $chips[] = $this->chip('register', 'Cadastro B2B', '', 'register');
        }

        return $chips;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{exit: bool, ready: bool, reply: string, state: array<string, mixed>}
     */
    private function ask(array $state, string $reply): array
    {
        return [
            'exit' => false,
            'ready' => false,
            'reply' => $reply,
            'state' => $state,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function mergeIntent(array $state, array $intent, string $message): array
    {
        $lower = mb_strtolower($message);

        if ($this->isSkipYear($lower)) {
            $state['year'] = 'qualquer';
        }

        if ($this->isSkipPart($lower)) {
            $state['skipped_part'] = true;
            $state['part'] = '';
        } elseif ($state['step'] !== self::STEP_YEAR && !$this->isSkipYear($lower)) {
            $fromIntent = $this->clip((string) ($intent['part'] ?? ''));
            if ($fromIntent !== '' && !$this->looksLikeNoisePart($fromIntent)) {
                $state['part'] = $fromIntent;
                $state['skipped_part'] = false;
            } elseif ($state['step'] === self::STEP_PART && $state['part'] === '' && $this->isBarePart($message)) {
                $state['part'] = $this->clip($message);
            }
        }

        $brand = $this->clip((string) ($intent['brand'] ?? ''));
        $model = $this->clip((string) ($intent['model'] ?? ''));
        $year = $this->clip((string) ($intent['year'] ?? ''));

        if ($brand !== '') {
            $state['brand'] = $brand;
        }
        if ($model !== '') {
            $state['model'] = $model;
        }
        if ($year !== '') {
            $state['year'] = $year;
        } elseif ($this->isSkipYear($lower)) {
            $state['year'] = 'qualquer';
        }

        return $state;
    }

    private function isExit(string $message): bool
    {
        $lower = mb_strtolower($message);

        return preg_match(
            '/\b(rastrear|rastreio|meu pedido|meus pedidos|nota fiscal|carrinho|'
            . 'cotações|cotacoes|atendente|whatsapp|login|cadastro|crédito|credito|'
            . 'central de ajuda)\b/u',
            $lower
        ) === 1;
    }

    private function isRestart(string $message): bool
    {
        $lower = mb_strtolower(trim($message));

        return in_array($lower, ['outra peça', 'outra peca', 'recomeçar', 'recomecar', 'outra busca'], true);
    }

    private function isSkipPart(string $lower): bool
    {
        return preg_match('/não sei o nome|nao sei o nome|não sei a peça|nao sei a peca/u', $lower) === 1;
    }

    private function isSkipYear(string $lower): bool
    {
        return preg_match('/qualquer ano|todos os anos|não sei o ano|nao sei o ano/u', $lower) === 1;
    }

    private function isBarePart(string $message): bool
    {
        $trimmed = trim($message);
        $len = mb_strlen($trimmed);
        if ($len < 3 || $len > 40) {
            return false;
        }
        if (preg_match('/[?]|(https?:)/u', $trimmed) === 1) {
            return false;
        }

        return preg_match('/^(oi|olá|ola|ok|obrigad[oa]|valeu)[\s!.]*$/iu', $trimmed) !== 1;
    }

    private function looksLikeNoisePart(string $part): bool
    {
        $lower = mb_strtolower($part);

        return in_array($lower, ['nome', 'peça', 'peca', 'moto', 'ajuda'], true);
    }

    /**
     * @return array{id: string, caption: string, value: string, action: string}
     */
    private function chip(string $id, string $caption, string $value, string $action): array
    {
        return [
            'id' => $id,
            'caption' => $caption,
            'value' => $value,
            'action' => $action,
        ];
    }

    private function clip(string $value): string
    {
        $clean = trim(strip_tags($value));
        if (mb_strlen($clean) > self::MAX_SLOT) {
            return mb_substr($clean, 0, self::MAX_SLOT);
        }

        return $clean;
    }
}
