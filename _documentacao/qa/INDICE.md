# QA ponta a ponta — índice

**Projeto:** AWA Motos — Magento 2.4.8-p5  
**Tema:** `AWA_Custom/ayo_home5_child`  
**Ambiente:** produção (`awamotos.com`)  
**Criado em:** 2026-09-14  
**Branch de referência:** `rescue/production-20260712`  
**Commit de referência:** `0a7e43137`  
**Última passagem:** 2026-09-14 — F2 fechada (20×3122, pedido `000000116`); P-010 retestado.

Este índice **não autoriza** alteração em produção. Cada escrita (código, config, cache, pedido, e-mail, WhatsApp, reimportação Sectra) exige diagnóstico, diff, backup, rollback e autorização explícita.

## Como usar

| Arquivo | Papel | Quando muda |
|---|---|---|
| [MATRIZ_TESTES.md](MATRIZ_TESTES.md) | Catálogo estável de casos (IDs nunca mudam) | Só para incluir caso novo ou corrigir o esperado |
| [CORRECOES_FASES.md](CORRECOES_FASES.md) | Patches `P-00n` com risco, backup e reteste | Quando um caso **falhou** |
| [execucoes/](execucoes/) | Diário da sessão (loja + admin + SSH) | Todo dia de auditoria |

Fluxo da sessão:

1. Abrir `execucoes/AAAA-MM-DD.md` (criar a partir do modelo no fim deste arquivo).
2. Copiar da matriz só os IDs da fase do dia.
3. Testar com **loja e admin visíveis**; crons, filas e logs só por SSH.
4. Marcar estado + evidência (URL, comportamento, trecho de log **sem segredo**).
5. Se falhou: abrir `P-00n` em correções. **Não** editar produção.
6. Depois da autorização: aplicar, retestar os IDs, fechar o patch.

## Fases (ordem de impacto na compra)

Alinhado à [playbook Adobe Commerce](https://experienceleague.adobe.com/en/docs/commerce-operations/implementation-playbook/best-practices/phases): teste, configuração, indexação e monitoramento são faixas distintas.

| Fase | Superfície | Objetivo | Efeito comercial |
|---|---|---|---|
| **F0** | Servidor + Git | Baseline: host, branch, worktree, cron, indexadores, cache, backup | Nenhum |
| **F1** | Loja guest | Home, busca, menu, filtros, PDP, assistente, 1366 e 390 | Nenhum |
| **F2** | Login → preço → carrinho → checkout | Conta B2B aprovada de teste; **um** place só com autorização pontual | Quote; pedido só se `C-PLACE-01` autorizado |
| **F3** | Conta / painel B2B | Empresa, permissões, histórico, logout, pendente vs aprovado | Não salvar cadastro real |
| **F4** | Admin Magento | Mesmo SKU, cliente e pedido da F2 | Somente leitura até haver correção |
| **F5** | Sectra | Importação, duplicidade, prospect, ACK | Sem reimportação em massa |
| **F6** | Correções | Um patch por risco | Só com backup + autorização |
| **F7** | Reteste e encerramento | IDs que falharam | Pedido real só com ID `C-PLACE-01` e autorização pontual |

Não pule F0. Não marque F2 como verificado só porque a PDP “parece” certa.

## Estados (só estes)

| Estado | Significado |
|---|---|
| `não testado` | Ainda não executado nesta era do documento |
| `verificado` | Passos, esperado e evidência batem |
| `falhou` | Esperado não ocorreu, ou o servidor gerou erro relacionado |
| `corrigido e retestado` | `P-00n` aplicado, IDs reexecutados, evidência nova |
| `bloqueado` | Falta credencial, autorização ou dependência |
| `fora de escopo` | Feature desligada por config, ou teste com efeito comercial sem autorização |

Uma fase só fecha quando **todos** os IDs obrigatórios dela estão `verificado` ou `corrigido e retestado`, ou explicitamente `fora de escopo`.

## Certeza

| Marcador | Uso |
|---|---|
| CONFIRMADA | Observado na sessão (browser, HTTP, SQL, log) |
| FORTE EVIDÊNCIA | Código + config + dado, sem o clique final |
| HIPÓTESE | Correlação; não promover a causa |

## Prefixo dos IDs

| Prefixo | Superfície |
|---|---|
| `L-` | Loja (home, busca, menu, filtro, PDP, mini-cart, assistente) |
| `B-` | Login B2B, cadastro, painel, empresa, permissões, preços da conta |
| `C-` | Carrinho e checkout |
| `A-` | Admin Magento |
| `S-` | Sectra (cliente, produto, preço, pedido, fila) |
| `V-` | Servidor (cron, indexador, fila, cache, log, backup, Git) |
| `P-` | Patch — nunca o mesmo ID do teste |

## Perfis

Testar separado, nunca misturar sessão:

- visitante anônimo (guest)
- cliente **pendente**
- cliente **aprovado** com preço ERP
- cliente aprovado **sem** validação Sectra / sem tabela
- administrador da empresa
- comprador com permissão limitada
- admin Magento (somente leitura na F4)

## Guardrails

- Não colocar senha, token, cookie, CNPJ completo, chave API ou frontName ofuscado do admin neste diretório.
- `/admin` público devolve 404; o admin real usa frontName ofuscado (ver `app/etc/env.php` no servidor, não neste repo de QA).
- Não disparar pedido real, cobrança, e-mail transacional, WhatsApp de teste em massa nem reimportação Sectra sem ID + autorização.
- Não editar `vendor/`, core Magento, `app/code/Rokanthemes/*` nem `pub/static` como fonte.
- Layout/CSS: plano canônico em [`../PLANO_CORRECAO_CASCATA_CSS_FASES.md`](../PLANO_CORRECAO_CASCATA_CSS_FASES.md). Este QA não substitui esse plano.
- Negócio B2B × ERP: mapa em [`../MAPA_OPERACIONAL_B2B_MAGENTO_SECTRA_2026-07-16.md`](../MAPA_OPERACIONAL_B2B_MAGENTO_SECTRA_2026-07-16.md). Números antigos do mapa **não** sobrescrevem a execução do dia.
- Não usar [`../Sectra/STATUS_RESUMIDO.md`](../Sectra/STATUS_RESUMIDO.md) como verdade atual.

## Viewports

`390` · `768` · `1366`. Caso sem viewport na matriz = 1366, salvo `*-M` (mobile).

## Modelo de execução diária

Copiar para `execucoes/AAAA-MM-DD.md`:

```md
# Execução AAAA-MM-DD

Fase: F_
Operador:
Loja (URL da sessão):
Admin: logado / não logado
Pedido real: não

| ID | Estado | Evidência | Nota |
|---|---|---|---|
| | | | |

Patches abertos:
Bloqueios:
```

## Progresso (atualizado na execução)

| Fase | Estado em 2026-09-14 |
|---|---|
| F0 Servidor | Parcial — baseline feita; worktree sujo permanece risco |
| F1 Loja guest | Parcial — home/busca/PDP/carrinho desktop; mobile e filtros incompletos |
| F2 Login–checkout | Bloqueada — sem conta B2B de teste na sessão |
| F3 Painel B2B | Não iniciada |
| F4 Admin | Bloqueada — tela de login apenas |
| F5 Sectra | Parcial — só SQL/cron |
| F6 Correções | P-001 diagnosticado, **não aplicado** |
| F7 Encerramento | Pendente |
