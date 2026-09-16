# Plano de Otimização de Duplicações CSS - AWA Motos

**Data:** 2026-06-05
**Objetivo:** Reduzir 1.015 duplicações do seletor `body#html-body:is(.cms-index-index...)`
**Status:** Análise Completa

---

## 1. Diagnóstico Detalhado

### 1.1 Arquivos com Mais Duplicações

| Arquivo | Ocorrências | Tamanho | Prioridade |
|---------|-------------|---------|------------|
| awa-commerce-impeccable-refine.css | 133 | 182KB | 🔴 Alta |
| awa-head-preload-critical-home.css | 97 | 14KB | 🔴 Alta |
| awa-bundle-refinements.css | 81 | 304KB | 🟡 Média |
| awa-home-body-end-bundle.css | 70 | 150KB | 🟡 Média |
| awa-ui-simplify-terminal.css | 67 | 41KB | 🟡 Média |
| awa-home-gate-polish-type.css | 25 | 648KB | 🟢 Baixa |
| awa-home-gate-polish-bundle.css | 25 | 876KB | 🟢 Baixa |

### 1.2 Natureza das Duplicações

As "duplicações" são na verdade **múltiplas regras diferentes** usando o mesmo prefixo de seletor:

```css
/* Exemplo - estas são regras DIFERENTES, não duplicatas */
body#html-body:is(.cms-index-index...) .page-wrapper .cart-summary { ... }
body#html-body:is(.cms-index-index...) .page-wrapper .product-thumb { ... }
body#html-body:is(.cms-index-index...) .page-wrapper .awa-shelf { ... }
```

**Isso NÃO é um bug** — é uma característica da arquitetura atual.

---

## 2. Problema Real Identificado

### 2.1 Especificidade Excessiva

Todos os arquivos usam seletores extremamente longos:

```css
html body#html-body:is(.cms-index-index, .cms-home, .cms-homepage_ayo_home5) .page-wrapper .element { ... }
```

**Impactos:**
- CSS parseado é maior do que necessário
- Dificuldade para sobrescrever regras
- Especificidade difícil de manter

### 2.2 Arquitetura Fragmentada

- **873KB** em awa-home-gate-polish-bundle.css
- **900KB** em awa-home-gate-postaudit-bundle.css
- **648KB** em awa-home-gate-polish-type.css

Total: **2.1MB+ apenas para CSS da home** (não minificado)

---

## 3. Estratégia de Otimização

### 3.1 Fase 1: Consolidação do Seletor Base (Não-invasivo)

**Ação:** Criar um arquivo canônico com o seletor base:

```css
/* awa-home-base-canonical.css */
/* Seletor base para todas as regras da home */
.awa-home-base,
.cms-index-index .page-wrapper,
.cms-home .page-wrapper,
.cms-homepage_ayo_home5 .page-wrapper {
  /* propriedades base compartilhadas */
}
```

**Benefício:** Reduzir repetição do seletor longo

### 3.2 Fase 2: Merge de Arquivos Home (Cuidadoso)

**Candidatos para consolidação:**
- awa-home-gate-polish-bundle.css (876KB)
- awa-home-gate-postaudit-bundle.css (900KB)
- awa-home-gate-polish-type.css (648KB)

**Arquivo resultante:** `awa-home-canonical-2026.css`

**Economia estimada:** 30-40% do tamanho total

### 3.3 Fase 3: Simplificação de Seletores

**Antes:**
```css
html body#html-body:is(.cms-index-index, .cms-home, .cms-homepage_ayo_home5) .page-wrapper .cart-summary { ... }
```

**Depois:**
```css
.cms-home .cart-summary,
.cms-index-index .cart-summary,
.cms-homepage_ayo_home5 .cart-summary { ... }
```

**Benefício:** 40% menos caracteres por seletor

---

## 4. Plano de Ação Recomendado

### Opção A: Abordagem Conservadora (Recomendada)

1. **Manter arquivos existentes** — funcionam corretamente
2. **Criar bundle consolidado paralelo** — para testes
3. **Testar A/B** — comparar performance
4. **Migrar gradualmente** — 1 arquivo por vez

**Risco:** Baixo
**Esforço:** Médio
**Benefício:** 20-30% redução CSS

### Opção B: Abordagem Agressiva

1. **Consolidar todos os bundles home**
2. **Simplificar seletores**
3. **Remover duplicações verdadeiras**

**Risco:** Alto (pode quebrar funcionalidades)
**Esforço:** Alto
**Benefício:** 50-60% redução CSS

---

## 5. Implementação Recomendada (Opção A)

### Passo 1: Criar Script de Análise

```bash
#!/bin/bash
# analyse-css-duplicates.sh

# Identificar regras verdadeiramente duplicadas
# (mesma propriedade + valor em arquivos diferentes)
```

### Passo 2: Criar Bundle Consolidado de Teste

```bash
# awa-home-canonical-consolidated.css
# Merge de:
# - awa-home-gate-polish-bundle.css
# - awa-home-gate-postaudit-bundle.css
# - awa-home-gate-polish-type.css
```

### Passo 3: Testes Visuais

- [ ] Home page em 390px, 768px, 1366px, 1920px
- [ ] Carrosséis funcionando
- [ ] Menu vertical
- [ ] Header alinhado
- [ ] Produtos e preços

### Passo 4: Deploy Gradual

1. Adicionar bundle consolidado como **fallback**
2. Remover arquivos antigos um por um
3. Monitorar métricas de performance

---

## 6. Comandos Úteis para Análise

```bash
# Contar linhas de cada arquivo home
du -h web/css/awa-home-*.css | sort -h

# Encontrar seletores mais comuns
grep -h "^html body#html-body" web/css/awa-*.css | sort | uniq -c | sort -rn | head -20

# Verificar se um seletor existe em múltiplos arquivos
grep -l "seletor-aqui" web/css/awa-*.css
```

---

## 7. Resumo de Decisão

| Aspecto | Decisão |
|---------|---------|
| Otimizar agora? | ⏸️ Não — site está funcionando |
| Abordagem | Conservadora (Opção A) |
| Prioridade | Baixa-Média |
| Próximo passo | Criar bundle de teste em ambiente seguro |

---

**Conclusão:** As "duplicações" são na verdade regras diferentes usando o mesmo prefixo de seletor. Não há bug funcional. A otimização traria benefícios de performance e manutenibilidade, mas introduziria risco. Recomendado: abordagem gradual com testes extensivos.

**Documento criado em:** 2026-06-05
**Próxima revisão:** Após próxima sprint de performance
