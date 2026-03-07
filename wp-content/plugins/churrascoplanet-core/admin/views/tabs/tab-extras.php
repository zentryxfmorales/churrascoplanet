<?php
/**
 * Tab: Extras de Productos
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

$option_name = $admin->get_option_name();
?>

<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-puzzle-piece"></i>
        <?php _e('Grupos de Extras / Adicionales', 'churrascoplanet-core'); ?>
    </h2>

    <p class="description"><?php _e('Crea grupos de opciones adicionales para tus productos (ej: "Elige tu proteina", "Bebidas de Lata"). Luego asignalos a cada producto desde la pestana de WooCommerce.', 'churrascoplanet-core'); ?></p>

    <div class="repeater-field repeater-nested" data-repeater="extras_grupos">
        <div class="repeater-items">
            <?php
            $extras_grupos = $admin->get_option('extras_grupos', array());
            foreach ($extras_grupos as $g_index => $grupo) :
            ?>
            <div class="repeater-item extras-group-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php echo esc_html($grupo['nombre'] ?? 'Grupo'); ?></span>
                    <?php if (!empty($grupo['requerido']) && $grupo['requerido'] === '1') : ?>
                        <span class="item-badge-required"><?php _e('Requerido', 'churrascoplanet-core'); ?></span>
                    <?php else : ?>
                        <span class="item-badge-optional"><?php _e('Opcional', 'churrascoplanet-core'); ?></span>
                    <?php endif; ?>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content extras-group-content">
                    <div class="option-field">
                        <label><?php _e('Nombre del Grupo', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[extras_grupos][<?php echo $g_index; ?>][nombre]"
                               value="<?php echo esc_attr($grupo['nombre'] ?? ''); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e('Ej: Bebidas de Lata', 'churrascoplanet-core'); ?>">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Instruccion', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[extras_grupos][<?php echo $g_index; ?>][instruccion]"
                               value="<?php echo esc_attr($grupo['instruccion'] ?? ''); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e('Ej: Elige 2 bebidas en latas', 'churrascoplanet-core'); ?>">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Es requerido?', 'churrascoplanet-core'); ?></label>
                        <select name="<?php echo $option_name; ?>[extras_grupos][<?php echo $g_index; ?>][requerido]">
                            <option value="0" <?php selected($grupo['requerido'] ?? '0', '0'); ?>><?php _e('Opcional', 'churrascoplanet-core'); ?></option>
                            <option value="1" <?php selected($grupo['requerido'] ?? '0', '1'); ?>><?php _e('Requerido', 'churrascoplanet-core'); ?></option>
                        </select>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Tipo de seleccion', 'churrascoplanet-core'); ?></label>
                        <select name="<?php echo $option_name; ?>[extras_grupos][<?php echo $g_index; ?>][tipo_seleccion]">
                            <option value="checkbox" <?php selected($grupo['tipo_seleccion'] ?? 'checkbox', 'checkbox'); ?>><?php _e('Multiple (checkbox)', 'churrascoplanet-core'); ?></option>
                            <option value="radio" <?php selected($grupo['tipo_seleccion'] ?? 'checkbox', 'radio'); ?>><?php _e('Una sola opcion (radio)', 'churrascoplanet-core'); ?></option>
                        </select>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Minimo de selecciones', 'churrascoplanet-core'); ?></label>
                        <input type="number"
                               name="<?php echo $option_name; ?>[extras_grupos][<?php echo $g_index; ?>][min]"
                               value="<?php echo esc_attr($grupo['min'] ?? '0'); ?>"
                               class="small-text" min="0">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Maximo de selecciones', 'churrascoplanet-core'); ?></label>
                        <input type="number"
                               name="<?php echo $option_name; ?>[extras_grupos][<?php echo $g_index; ?>][max]"
                               value="<?php echo esc_attr($grupo['max'] ?? '0'); ?>"
                               class="small-text" min="0">
                        <p class="description"><?php _e('0 = sin limite', 'churrascoplanet-core'); ?></p>
                    </div>

                    <!-- Items del grupo -->
                    <div class="option-field full-width">
                        <label><?php _e('Opciones del Grupo', 'churrascoplanet-core'); ?></label>
                        <div class="extras-items-list">
                            <?php
                            $items = $grupo['items'] ?? array();
                            foreach ($items as $i_index => $item) :
                            ?>
                            <div class="extras-item-row">
                                <span class="drag-handle-sm"><i class="fas fa-grip-vertical"></i></span>
                                <input type="text"
                                       name="<?php echo $option_name; ?>[extras_grupos][<?php echo $g_index; ?>][items][<?php echo $i_index; ?>][nombre]"
                                       value="<?php echo esc_attr($item['nombre'] ?? ''); ?>"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e('Nombre del extra', 'churrascoplanet-core'); ?>">
                                <input type="number"
                                       name="<?php echo $option_name; ?>[extras_grupos][<?php echo $g_index; ?>][items][<?php echo $i_index; ?>][precio]"
                                       value="<?php echo esc_attr($item['precio'] ?? '0'); ?>"
                                       class="small-text extras-price-input"
                                       min="0" step="1"
                                       placeholder="$0">
                                <span class="extras-price-label">CLP</span>
                                <button type="button" class="button-link remove-extras-item" title="<?php esc_attr_e('Eliminar', 'churrascoplanet-core'); ?>"><i class="fas fa-times"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button button-small add-extras-item" data-group="<?php echo $g_index; ?>">
                            <i class="fas fa-plus"></i> <?php _e('Agregar Opcion', 'churrascoplanet-core'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button add-repeater-item" id="add-extras-group">
            <i class="fas fa-plus"></i> <?php _e('Agregar Grupo de Extras', 'churrascoplanet-core'); ?>
        </button>
        <script type="text/html" class="repeater-template">
            <div class="repeater-item extras-group-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php _e('Nuevo Grupo', 'churrascoplanet-core'); ?></span>
                    <span class="item-badge-optional"><?php _e('Opcional', 'churrascoplanet-core'); ?></span>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content extras-group-content">
                    <div class="option-field">
                        <label><?php _e('Nombre del Grupo', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[extras_grupos][{{index}}][nombre]" value="" class="regular-text" placeholder="<?php esc_attr_e('Ej: Bebidas de Lata', 'churrascoplanet-core'); ?>">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Instruccion', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[extras_grupos][{{index}}][instruccion]" value="" class="regular-text" placeholder="<?php esc_attr_e('Ej: Elige 2 bebidas en latas', 'churrascoplanet-core'); ?>">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Es requerido?', 'churrascoplanet-core'); ?></label>
                        <select name="<?php echo $option_name; ?>[extras_grupos][{{index}}][requerido]">
                            <option value="0"><?php _e('Opcional', 'churrascoplanet-core'); ?></option>
                            <option value="1"><?php _e('Requerido', 'churrascoplanet-core'); ?></option>
                        </select>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Tipo de seleccion', 'churrascoplanet-core'); ?></label>
                        <select name="<?php echo $option_name; ?>[extras_grupos][{{index}}][tipo_seleccion]">
                            <option value="checkbox"><?php _e('Multiple (checkbox)', 'churrascoplanet-core'); ?></option>
                            <option value="radio"><?php _e('Una sola opcion (radio)', 'churrascoplanet-core'); ?></option>
                        </select>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Minimo de selecciones', 'churrascoplanet-core'); ?></label>
                        <input type="number" name="<?php echo $option_name; ?>[extras_grupos][{{index}}][min]" value="0" class="small-text" min="0">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Maximo de selecciones', 'churrascoplanet-core'); ?></label>
                        <input type="number" name="<?php echo $option_name; ?>[extras_grupos][{{index}}][max]" value="0" class="small-text" min="0">
                        <p class="description"><?php _e('0 = sin limite', 'churrascoplanet-core'); ?></p>
                    </div>
                    <div class="option-field full-width">
                        <label><?php _e('Opciones del Grupo', 'churrascoplanet-core'); ?></label>
                        <div class="extras-items-list"></div>
                        <button type="button" class="button button-small add-extras-item" data-group="{{index}}">
                            <i class="fas fa-plus"></i> <?php _e('Agregar Opcion', 'churrascoplanet-core'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </script>
    </div>
</div>

<div class="info-box">
    <i class="fas fa-info-circle"></i>
    <p><?php _e('Despues de crear los grupos de extras aqui, ve a cada producto en WooCommerce > Productos y asigna los grupos que correspondan desde la seccion "Extras ChurrascoPlanet".', 'churrascoplanet-core'); ?></p>
</div>
