# Changelog

## [1.0.0] - 2026-11-16

### Adicionado
- Compatibilidade unificada para GLPI 10.0.0 até 12.0.99 e PHP 7.4+.
- Tela de configuração em **Configurar > Plugins > Essentials** com 5 opções independentes iniciando desativadas por padrão.
- Pausa automática de SLA (`TTR`) para chamados no status **Planejado** e/ou com validação **Aguardando aprovação**, usando o calendário da SLA com fallback para relógio corrido e proteção contra contagem duplicada em sobreposição ou transição para **Pendente**.
- Banner informativo na ficha do chamado exibindo motivos ativos e início da pausa de SLA.
- Ocultação do código da categoria nos seletores (`ajax/categories.php` + JavaScript) preservando nomes com hífens sem código cadastrado.
- Botão de cópia rápida de `#ID - Título` e link do chamado para a área de transferência.
- Suíte de testes automatizados em PHP (`tests/run.php`) e JavaScript (`tests/test_js.mjs`).
