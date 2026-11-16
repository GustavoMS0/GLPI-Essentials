# Essentials para GLPI

Melhorias operacionais opcionais para o fluxo de chamados no GLPI 10, 11 e 12, com todos os recursos desativados por padrão.

| Versão do GLPI | PHP mínimo | Suporte |
|---|---|---|
| **12.0.x** | 8.3+ | ✅ |
| **11.0.x** | 8.2+ | ✅ |
| **10.0.x** | 7.4+ | ✅ |

---

## Recursos

Todos os módulos podem ser habilitados ou desabilitados de forma independente em **Configurar > Plugins > Essentials**:

1. **Ocultar código da categoria nos seletores (`hide_category_code`)**
   - Consulta as categorias visíveis com `code` preenchido no banco via `ajax/categories.php` e remove apenas o sufixo exato `" - <código>"` nos seletores de categoria (incluindo Select2 dinâmico).
   - Preserva a hierarquia completa (`completename`) e mantém intactos hífens legítimos em nomes de categorias sem código (por exemplo, `"ERP - Financeiro"` permanece inalterado).

2. **Pausa automática de SLA em aprovação pendente (`pause_sla_approval`)**
   - Pausa a contagem do SLA de solução (`TTR`) enquanto `global_validation` estiver como Aguardando (`2`).

3. **Pausa automática de SLA no status Planejado (`pause_sla_planned`)**
   - Pausa a contagem do SLA de solução (`TTR`) enquanto o chamado estiver no status Planejado (`3`).

4. **Banner de SLA pausado no chamado (`ticket_sla_pause_banner`)**
   - Exibe um aviso discreto na ficha do chamado mostrando os motivos ativos de pausa e o horário inicial registrado.

5. **Botão de cópia rápida no chamado (`ticket_quick_copy_link`)**
   - Adiciona um botão para copiar `#ID - Título` e a URL direta do chamado com um clique.

---

## Regras de cálculo e segurança do SLA

- **Sem dupla contagem em pausas simultâneas:** se o chamado estiver ao mesmo tempo Planejado e Aguardando Aprovação, ao encerrar apenas um dos motivos o plugin mantém o início mais antigo na pausa remanescente e aplica a compensação somente quando o último motivo se encerra.
- **Transição para Pendente (`WAITING = 4`):** se o chamado entrar no status Pendente nativo do GLPI enquanto houver pausa aberta do plugin, a pausa do plugin é encerrada e compensada até aquele instante antes que o GLPI assuma a contagem nativa de espera. Enquanto permanecer em `WAITING`, `SOLVED`, `CLOSED`, excluído (`is_deleted = 1`) ou sem `time_to_resolve`, nenhuma pausa do plugin é contabilizada.
- **Respeito ao calendário da SLA:** ao encerrar uma pausa, o tempo ativo é calculado via `Calendar::getActiveTimeBetween($paused_at, $now)` quando a SLA possui calendário vinculado, com fallback para diferença de relógio quando não há calendário.
- **Compensação de prazo:** soma o tempo pausado em `sla_waiting_duration`, recalcula `time_to_resolve` com `SLA::computeDate($date, $newWaiting)` quando há SLA de solução configurada (ou soma os segundos ao prazo atual) e registra entrada no histórico do chamado.

---

## Instalação

1. Baixe o pacote `essentials-<versao>.zip` na página de [Releases](https://github.com/GustavoMS0/GLPI-Essentials/releases).
2. Extraia a pasta `essentials` dentro do diretório `plugins/` da sua instalação do GLPI.
3. No GLPI, acesse **Configurar > Plugins**, clique em **Instalar** e depois em **Ativar**.
4. Clique no ícone de configuração do **Essentials** e habilite os recursos desejados.

---

## Testes locais

Os testes unitários simulam o núcleo do GLPI 10, 11 e 12 sem dependências externas:

```bash
php tests/run.php
node --check essentials/public/js/essentials.js
node tests/test_js.mjs
```

---

## Licença

Copyright (C) 2026 by G. Martins.  
Distribuído sob os termos da licença **GPL-3.0-or-later** ([LICENSE](LICENSE)).
