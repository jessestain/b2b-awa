---
target: ai chatbot widget floating assistant
total_score: 21
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 3
timestamp: 2026-08-14T01-18-19Z
slug: ssistant-view-frontend-templates-chat-widget-phtml
---
# Critique — Assistente AWA (FAB + coach + painel)

Alvo: `app/code/GrupoAwamotos/AiAssistant/view/frontend/templates/chat/widget.phtml`
Live: https://awamotos.com/

## Heuristics

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | Typing dots existem; FAB fechado sem estado; AJAX até 60s sem progresso; coach auto-dismiss 20s |
| 2 | Match System / Real World | 3 | PT-BR e copy de peça/B2B; ícone genérico; welcome promete rastrear pedido a guest |
| 3 | User Control and Freedom | 2 | Fecha via X/FAB; sem Esc, sem focus trap, sem aria-modal; coach auto-dismiss |
| 4 | Consistency and Standards | 2 | Guerra módulo vs DS: FAB 56×44, coach morto, z-index 9999 vs token 250 |
| 5 | Error Prevention | 2 | maxlength e send vazio ok; sem chips; welcome induz tarefa impossível (pedido sem login) |
| 6 | Recognition Rather Than Recall | 2 | FAB icon-only; zero chips no empty state; coach (reconhecimento) está display:none |
| 7 | Flexibility and Efficiency | 2 | Enter envia; isB2B não muda UI; histórico só em memória |
| 8 | Aesthetic and Minimalist Design | 2 | Painel interno limpo; overlay mobile disputa com cookie + bottom nav + Carrinho |
| 9 | Error Recovery | 2 | Erro genérico duplicado (alert + bolha); sem Retry |
| 10 | Help and Documentation | 2 | Widget é a ajuda; how-to do coach aponta FAQ e está morto no CSS |
| **Total** | | **21/40** | **Acceptable** |

## Design Specificity

Category-interchangeable: Intercom/Crisp pintado de `--awa-primary`. O caráter AWA (fitment, Cadastro B2B, Repetir compra) está no coach e nos product cards — o DS mata o coach e o empty state não mostra cards.

## Cognitive Load

7/8 falhas (high): single focus, chunking, hierarchy, one-thing, minimal choices, working memory, progressive disclosure. Pass: grouping.

## Priority Issues

- [P0] Overlay Persuade morto; FAB mudo. DS §34.3 `display:none !important` no `.awa-ai-coach`; `ai-assistant.min.css` servido sem regras de coach (fonte LESS tem 21).
- [P1] Polegar: FAB 56×44 colado na bottom nav (gap 4px). Token `--awa-mobile-bottom-nav-h` = 64px; nav real 72px.
- [P1] Empty state não opera: welcome genérico, zero chips, `isB2B` dead no chrome.
- [P1] Dialog a11y: dois role=dialog, sem Esc/trap/aria-modal; aria-expanded omitido quando false.
- [P2] Forma genérica; z-index 9999 (debug) vs `--awa-z-floating-btn: 250`.

## Personas

Casey: FAB × Carrinho ~4px. Jordan: ícone mudo, coach morto, login remove o widget. Sam: sem Esc/trap. Lojista B2B: quer SKU/moto/reorder, recebe textarea livre.

## Detector

CLI `detect.mjs` = [] (exit 0). Falso negativo: squash e hide só na cascata runtime.

## Visual overlays

Mutation allowed. Overlay não injetado (produção). Sem overlay visível para o humano.
