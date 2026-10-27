# Operação de produção — awamotos.com

Este repositório é a fonte de verdade do site em produção.
Branch de produção: `rescue/production-20260712` (default).

Servidor: `awamotos.com` (`72.61.94.22`), raiz `/home/user/htdocs/srv1113343.hstgr.cloud`,
Magento 2.4.8-p5 em modo production, tema `AWA_Custom/ayo_home5_child`.

## Publicar uma mudança

A ferramenta `awa` roda no servidor (`/usr/local/bin/awa`) e protege cada alteração
com snapshot + rollback automático.

```bash
cd /home/user/htdocs/srv1113343.hstgr.cloud

awa begin <arquivos...>    # congela o estado anterior
# ... editar ...
awa finish                 # lint -> deploy do tipo certo -> verify (rollback se falhar)
awa save "fix(theme): descrição da mudança"   # commit + push
```

## O que cada tipo de arquivo dispara

| Arquivo | Ação | Tempo |
|---|---|---|
| `.phtml` | espelha em `var/view_preprocessed` + `cache:clean` | ~5–20 s |
| `.php` / `.xml` de layout | `cache:clean` | ~5–20 s |
| `web/css`, `web/js` do tema | `bin/deploy-static-safe --apply` + `cache:flush` | ~24 s |
| `.less` / CSS de módulo | `setup:static-content:deploy` + `cache:flush` | ~9 s |
| `etc/di.xml`, `module.xml` | bloqueado no `awa` — usar `bin/deploy-full` | minutos |

## Reverter

```bash
awa abort                  # desfaz o begin ativo
awa rollback <timestamp>   # restaura um backup específico
```

## Diagnóstico

```bash
awa status     # HTTP, modo, serviços, git, disco
awa verify     # home, carrinho, login, robots, 404 + logs
awa logs 30    # exception.log e system.log
```

## Regras que não podem ser quebradas

1. Nunca editar `app/code/Rokanthemes/*` nem `vendor/` — override no tema filho.
2. CSS: usar tokens (`var(--awa-red)`), nunca hex fixo.
3. Nomes de arquivo CSS são contrato: plugins PHP casam por regex de nome; renomear
   quebra a injeção silenciosamente.
4. DI/composer: sempre `bin/deploy-full`, nunca pelo `awa`.
