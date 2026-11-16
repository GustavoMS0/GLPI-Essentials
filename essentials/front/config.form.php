<?php

/**
 * ------------------------------------------------------------------------
 * Essentials - Plugin configuration page
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Essentials.
 *
 * Essentials is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Essentials is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * ------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    include_once __DIR__ . '/../../../inc/includes.php';
}

require_once __DIR__ . '/../inc/compat.class.php';
require_once __DIR__ . '/../inc/config.class.php';

Session::checkRight('config', READ);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['update'])) {
    Session::checkRight('config', UPDATE);
    PluginEssentialsCompat::requireSafePost();
    PluginEssentialsConfig::save($_POST);
    Session::addMessageAfterRedirect(__('Configurações salvas com sucesso.', 'essentials'), false, INFO);
    Html::back();
}

$canUpdate   = Session::haveRight('config', UPDATE);
$current     = PluginEssentialsConfig::getAll();
$definitions = PluginEssentialsConfig::getOptionDefinitions();
$formAction  = PluginEssentialsCompat::webPath('front/config.form.php');

$grouped = [];
foreach ($definitions as $key => $meta) {
    $groupName = (string) ($meta['group'] ?? __('Geral', 'essentials'));
    $grouped[$groupName][$key] = $meta;
}

Html::header(
    PluginEssentialsConfig::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);
?>
<style>
.glpi-essentials-cfg {
    max-width: 900px;
    margin: 1.5rem auto;
}
.glpi-essentials-cfg-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--tblr-border-color, #dee2e6);
}
.glpi-essentials-cfg-title {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 600;
}
.glpi-essentials-cfg-sub {
    margin: 0.25rem 0 0;
    font-size: 0.875rem;
    color: var(--tblr-muted, #626976);
}
.glpi-essentials-cfg-section {
    padding: 1.25rem 1.5rem 0.25rem;
}
.glpi-essentials-cfg-group-title {
    margin: 0 0 0.75rem;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--tblr-muted, #626976);
}
.glpi-essentials-toggle-card {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.125rem;
    margin-bottom: 0.85rem;
    border: 1px solid var(--tblr-border-color, #dce1e7);
    border-radius: 0.5rem;
    background: var(--tblr-bg-surface, #ffffff);
}
.glpi-essentials-toggle-card:hover {
    border-color: var(--tblr-primary, #2f6690);
}
.glpi-essentials-toggle-label {
    font-weight: 600;
    font-size: 0.95rem;
    display: block;
    margin-bottom: 0.25rem;
    cursor: pointer;
}
.glpi-essentials-toggle-desc {
    margin: 0;
    font-size: 0.85rem;
    line-height: 1.45;
    color: var(--tblr-muted, #5c6675);
}
.glpi-essentials-toggle-ctrl {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    flex-shrink: 0;
    padding-top: 0.15rem;
}
.glpi-essentials-cfg-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.5rem 1.25rem;
    border-top: 1px solid var(--tblr-border-color, #dee2e6);
}
</style>

<div class="glpi-essentials-cfg">
    <div class="card shadow-sm">
        <div class="glpi-essentials-cfg-header">
            <div>
                <h2 class="glpi-essentials-cfg-title">
                    <i class="ti ti-adjustments-horizontal me-2" aria-hidden="true"></i>
                    <?php echo htmlspecialchars(__('Essentials — Configurações', 'essentials'), ENT_QUOTES, 'UTF-8'); ?>
                </h2>
                <p class="glpi-essentials-cfg-sub">
                    <?php echo htmlspecialchars(__('Todos os recursos iniciam desativados por padrão. Ative apenas o que fizer sentido para a operação.', 'essentials'), ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
            <span class="badge bg-azure-lt text-azure">
                <?php echo htmlspecialchars('v' . PLUGIN_ESSENTIALS_VERSION, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>

        <form method="post" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>">
            <?php foreach ($grouped as $groupTitle => $items): ?>
                <div class="glpi-essentials-cfg-section">
                    <h3 class="glpi-essentials-cfg-group-title">
                        <?php echo htmlspecialchars((string) $groupTitle, ENT_QUOTES, 'UTF-8'); ?>
                    </h3>

                    <?php foreach ($items as $key => $meta): ?>
                        <?php
                        $isOn     = !empty($current[$key]);
                        $inputId  = 'glpi_essentials_' . preg_replace('/[^a-z0-9_]/i', '_', (string) $key);
                        $disabled = $canUpdate ? '' : ' disabled="disabled"';
                        $checked  = $isOn ? ' checked="checked"' : '';
                        ?>
                        <div class="glpi-essentials-toggle-card">
                            <div>
                                <label class="glpi-essentials-toggle-label" for="<?php echo htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars((string) $meta['title'], ENT_QUOTES, 'UTF-8'); ?>
                                </label>
                                <p class="glpi-essentials-toggle-desc">
                                    <?php echo htmlspecialchars((string) $meta['description'], ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                            <div class="glpi-essentials-toggle-ctrl">
                                <span class="badge <?php echo $isOn ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary'; ?>">
                                    <?php echo htmlspecialchars($isOn ? __('Ativo', 'essentials') : __('Inativo', 'essentials'), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <div class="form-check form-switch m-0">
                                    <?php if ($canUpdate): ?>
                                        <input type="hidden" name="<?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?>" value="0">
                                    <?php endif; ?>
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        id="<?php echo htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8'); ?>"
                                        name="<?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?>"
                                        value="1"<?php echo $checked . $disabled; ?>
                                    >
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div class="glpi-essentials-cfg-footer">
                <?php if ($canUpdate): ?>
                    <button type="submit" name="update" value="1" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars(__('Salvar', 'essentials'), ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                <?php else: ?>
                    <span class="text-muted small">
                        <?php echo htmlspecialchars(__('Modo somente leitura (sem permissão de edição em Configuração).', 'essentials'), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php Html::closeForm(); ?>
    </div>
</div>
<?php
Html::footer();
