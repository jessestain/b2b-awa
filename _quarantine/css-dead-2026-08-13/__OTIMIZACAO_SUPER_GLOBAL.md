# Otimização awa-super-global.css — 2026-06-05

## Resumo da Otimização

| Métrica | Antes | Depois | Economia |
|---------|-------|--------|----------|
| CSS crítico | 2.1MB | 1.6MB | -700KB (33%) |
| CSS lazy | — | 276KB | Carregado sob demanda |
| Total | 2.1MB | 1.6MB + 276KB | -700KB no first paint |

## Arquivos Criados

1. **awa-super-global-core.min.css** (1.6MB) — CSS crítico carregado em todas as páginas
   - Tokens CSS globais
   - Reset e base
   - Grid system
   - Componentes essenciais
   - NÃO inclui menu vertical completo

2. **awa-vertical-menu-lazy.min.css** (276KB) — CSS do menu vertical
   - 15 phases do menu vertical
   - Deve ser carregado lazy (async)
   - Só necessário quando o menu está presente

## Configuração de Carregamento

### Opção 1: Substituir super-global pelo core (RISCO: ALTO)

Alterar em `default_head_blocks.xml` ou `awa-head-preload.phtml`:
```php
// ANTES:
$superGlobalUrl = $block->getViewFileUrl('css/awa-super-global.min.css');

// DEPOIS:
$superGlobalUrl = $block->getViewFileUrl('css/awa-super-global-core.min.css');
```

E adicionar lazy load do menu:
```php
$verticalMenuUrl = $block->getViewFileUrl('css/awa-vertical-menu-lazy.min.css');
// Carregar com media="print" onload ou após interação
```

### Opção 2: Manter super-global como safety (RECOMENDADO)

Manter o arquivo original funcionando, e usar a otimização como:
1. **Teste A/B** — comparar métricas
2. **Fallback** — se o core funcionar, migrar gradualmente
3. **Futura consolidação** — após validação completa

## Riscos e Mitigações

| Risco | Mitigação |
|-------|-----------|
| Menu vertical sem estilo | Carregar lazy CSS antes de abrir menu |
| Outros elementos quebrados | Testar todas as páginas antes de deploy |
| Cache antigo | Limpar FPC e Redis após deploy |
| Especificidade CSS | Verificar se core tem tokens necessários |

## Próximos Passos

1. **Testar em ambiente seguro** — staging/local
2. **Auditar visualmente** — menu, header, footer em todas as páginas
3. **Medir performance** — Lighthouse antes/depois
4. **Deploy gradual** — 10% → 50% → 100% de tráfego

## Comandos Úteis

```bash
# Verificar se arquivos estão em pub/static
ls -la pub/static/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-super-global-core.min.css
ls -la pub/static/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-vertical-menu-lazy.min.css

# Limpar cache
sudo -u www-data php bin/magento cache:clean full_page block_html
redis-cli -n 2 FLUSHDB

# Verificar carregamento no browser
curl -s https://awamotos.com/static/versionXXX/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-super-global-core.min.css -I
```
