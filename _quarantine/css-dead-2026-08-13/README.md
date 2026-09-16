# Quarentena de CSS mortos — 2026-08-13

Onda 1 + recorte seguro da onda 2 da Fase 11. Arquivos saíram de
`app/design/frontend/AWA_Custom/ayo_home5_child/web/css/` para cá porque o
Magento 2 publica **todo** o `web/` em `setup:static-content:deploy`.

Backup byte a byte (fora do DocumentRoot):
`/home/deploy/backups/awa-css-dead-2026-08-13/`

## O que foi movido

Lixo / docs no `web/` (não são stylesheet da cascata):

- `awa-vfix-cms-b2b.css.bak-20260719_223913` (não rastreados no Git)
- `awa-home-qa-batch-2026-07-22.css`
- `__ANALISE_DUPLICACOES_2026-06-05.md`
- `__CORRECAO_LAYOUT_SHIFT_2026-06-05.md`
- `__OTIMIZACAO_DUPLICACOES_PLANO.md`
- `__OTIMIZACAO_SUPER_GLOBAL.md`

`.min.css` sem `<link>` / `getViewFileUrl` ativo (HTML ao vivo 2026-08-13):

- `awa-plp-distill.min.css`
- `awa-flex-grid-flow.min.css`
- `awa-home-flex-grid-flow.min.css`
- `awa-home-hover-lock.min.css`
- `awa-plp-final-polish.min.css`
- `awa-home-standardize-terminal-wins-2026-06-09.min.css` (após desligar o injetor em `awa-css-gate.js`)

## O que NÃO foi movido (de propósito)

- Shims em `LEGACY_STATIC_SHIMS.md` (URL stale / FPC)
- `awa-visual-noise-*` e `awa-cookie-fab-collision-*` (fonte residual do deferred stack)
- `awa-checkout-polish.min.css` (ainda emitido no checkout)
- Cópias em `pub/static` dos CSS quarentenados — permanecem até o próximo static deploy
  (`awa-css-gate.min.js` publicado nesta onda: query `20260813-retire-standardize`)

## Rollback

```bash
git mv _quarantine/css-dead-2026-08-13/<arquivo> \
  app/design/frontend/AWA_Custom/ayo_home5_child/web/css/<arquivo>
```

O `.bak` não estava no Git: restaurar de
`/home/deploy/backups/awa-css-dead-2026-08-13/`.

Não é deleção. Remoção definitiva só depois de revisão e de o próximo
`static-content:deploy` do tema filho.
