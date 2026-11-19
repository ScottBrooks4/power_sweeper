<?php

declare(strict_types=1);

/**
 * Modern → classic control swaps for gblTheme dark-mode prep.
 *
 * Power Sweeper themes via Fill / Color formulas on gblTheme.*. Fluent / canvas
 * modern controls often expose only Appearance + BasePaletteColor / FontColor and
 * cannot take a reliable page/input background or text color. Prefer classic
 * templates that still support those properties before enable_dark_mode runs.
 *
 * Each entry:
 * - match: regexes tested against the lowercased Control / Template.Name string
 * - yaml: YAML Control: value to write
 * - json: Template Id / Name / Version for JSON packs
 * - missing: which theme props the source typically lacks (documentation)
 * - property_map: rename modern props onto classic equivalents
 * - remove: modern-only props to drop after remap
 * - optional: skipped unless sweep option enable_<key> is true
 *
 * @return list<array{
 *   key:string,
 *   match:list<string>,
 *   yaml:string,
 *   json:array{Id:string,Name:string,Version:string},
 *   missing:list<string>,
 *   property_map?:array<string,string>,
 *   remove?:list<string>,
 *   optional?:bool
 * }>
 */
return [
    [
        'key' => 'modern_button',
        'match' => [
            '#^modernbutton(?:@|$)#i',
            '#^button@0\\.0\\.#i',
            '#powerapps_corecontrols_buttoncanvas#i',
            '#buttoncanvas#i',
        ],
        'yaml' => 'Classic/Button@2.2.0',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/button',
            'Name' => 'button',
            'Version' => '2.2.0',
        ],
        'missing' => ['Fill'],
        'property_map' => [
            'FontColor' => 'Color',
            'BasePaletteColor' => 'Fill',
            'FontSize' => 'Size',
        ],
        'remove' => ['Appearance', 'BasePaletteColor', 'FontColor', 'FontSize', 'IconStyle', 'Layout'],
    ],
    [
        'key' => 'modern_text',
        'match' => [
            '#^moderntext(?:@|$)#i',
            '#^text@0\\.0\\.#i',
            '#powerapps_corecontrols_textcanvas#i',
            '#textcanvas#i',
        ],
        'yaml' => 'Label@2.5.1',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/label',
            'Name' => 'label',
            'Version' => '2.5.1',
        ],
        'missing' => ['Fill'],
        'property_map' => [
            'FontColor' => 'Color',
            'FontSize' => 'Size',
        ],
        'remove' => ['FontColor', 'Appearance', 'BasePaletteColor', 'FontSize'],
    ],
    [
        'key' => 'modern_text_input',
        'match' => [
            '#^moderntextinput(?:@|$)#i',
            '#^textinput@0\\.0\\.#i',
            '#textinputcanvas#i',
            '#powerapps_corecontrols_textinput#i',
        ],
        'yaml' => 'Classic/TextInput@2.3.2',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/text',
            'Name' => 'text',
            'Version' => '2.3.2',
        ],
        'missing' => ['Color'],
        'property_map' => [
            'FontColor' => 'Color',
            'FontSize' => 'Size',
            'Placeholder' => 'HintText',
            'PlaceholderText' => 'HintText',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'TriggerOutput', 'ValidationState', 'Type', 'Placeholder', 'PlaceholderText', 'FontSize'],
    ],
    [
        'key' => 'modern_dropdown',
        'match' => [
            '#^moderndropdown(?:@|$)#i',
            '#^dropdown@0\\.0\\.#i',
            '#dropdowncanvas#i',
            '#powerapps_corecontrols_dropdown#i',
        ],
        'yaml' => 'Classic/DropDown@2.3.1',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/dropdown',
            'Name' => 'dropdown',
            'Version' => '2.3.1',
        ],
        'missing' => ['Color'],
        'property_map' => [
            'FontColor' => 'Color',
            'FontSize' => 'Size',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'ValidationState', 'FontSize'],
    ],
    [
        'key' => 'modern_combobox',
        'match' => [
            '#^moderncombobox(?:@|$)#i',
            '#^combobox@0\\.0\\.#i',
            '#comboboxcanvas#i',
            '#powerapps_corecontrols_combobox#i',
        ],
        'yaml' => 'Classic/ComboBox@2.4.0',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/combobox',
            'Name' => 'combobox',
            'Version' => '2.4.0',
        ],
        'missing' => ['Color'],
        'property_map' => [
            'FontColor' => 'Color',
            'FontSize' => 'Size',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'ValidationState', 'FontSize'],
    ],
    [
        'key' => 'modern_datepicker',
        'match' => [
            '#^moderndatepicker(?:@|$)#i',
            '#^datepicker@0\\.0\\.#i',
            '#datepickercanvas#i',
            '#powerapps_corecontrols_datepicker#i',
        ],
        'yaml' => 'Classic/DatePicker@2.6.0',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/datepicker',
            'Name' => 'datepicker',
            'Version' => '2.6.0',
        ],
        'missing' => ['Color'],
        'property_map' => [
            'FontColor' => 'Color',
            'FontSize' => 'Size',
            'Value' => 'DefaultDate',
            'DateTimeFormat' => 'Format',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'ValidationState', 'Value', 'FontSize', 'DateTimeFormat'],
    ],
    [
        'key' => 'modern_checkbox',
        'match' => [
            '#^moderncheckbox(?:@|$)#i',
            '#^checkbox@0\\.0\\.#i',
            '#checkboxcanvas#i',
            '#powerapps_corecontrols_checkbox#i',
        ],
        'yaml' => 'Classic/CheckBox@2.1.0',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/checkbox',
            'Name' => 'checkbox',
            'Version' => '2.1.0',
        ],
        'missing' => ['Fill', 'Color'],
        'property_map' => [
            'FontColor' => 'Color',
            'FontSize' => 'Size',
            'BasePaletteColor' => 'Fill',
            'Checked' => 'Default',
            'Label' => 'Text',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'Checked', 'Label', 'FontSize'],
    ],
    [
        'key' => 'modern_toggle',
        'match' => [
            '#^moderntoggle(?:@|$)#i',
            '#^toggle@0\\.0\\.#i',
            '#togglecanvas#i',
            '#powerapps_corecontrols_toggle#i',
        ],
        'yaml' => 'Classic/Toggle@2.1.0',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/toggle',
            'Name' => 'toggle',
            'Version' => '2.1.0',
        ],
        'missing' => ['Fill', 'Color'],
        'property_map' => [
            'FontColor' => 'Color',
            'FontSize' => 'Size',
            'BasePaletteColor' => 'TrueFill',
            'Checked' => 'Default',
            'Label' => 'TrueText',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'Checked', 'Label', 'LabelPosition', 'FontSize'],
    ],
    [
        'key' => 'modern_radio',
        'match' => [
            '#^modernradio(?:@|$)#i',
            '#^radio@0\\.0\\.#i',
            '#radiogroupcanvas#i',
            '#radiocanvas#i',
            '#powerapps_corecontrols_radio#i',
        ],
        'yaml' => 'Classic/Radio@2.3.0',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/radio',
            'Name' => 'radio',
            'Version' => '2.3.0',
        ],
        'missing' => ['Fill', 'Color'],
        'property_map' => [
            'FontColor' => 'Color',
            'FontSize' => 'Size',
            // BasePaletteColor is the selected-dot accent, not the control Fill.
            // Mapping it paints a solid black/brand bar over Language/Theme radios.
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'ValidationState', 'FontSize'],
    ],
    [
        'key' => 'modern_slider',
        'match' => [
            '#^modernslider(?:@|$)#i',
            '#^slider@0\\.0\\.#i',
            '#slidercanvas#i',
            '#powerapps_corecontrols_slider#i',
        ],
        'yaml' => 'Classic/Slider@2.1.0',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/slider',
            'Name' => 'slider',
            'Version' => '2.1.0',
        ],
        'missing' => ['Fill'],
        'property_map' => [
            'FontColor' => 'Color',
            'BasePaletteColor' => 'HandleFill',
            'Value' => 'Default',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'Value'],
    ],
    [
        'key' => 'modern_icon',
        'match' => [
            '#^modernicon(?:@|$)#i',
            '#^icon@0\\.0\\.#i',
            '#powerapps_corecontrols_icon(?:@|$)#i',
        ],
        'yaml' => 'Classic/Icon@2.5.0',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/icon',
            'Name' => 'icon',
            'Version' => '2.5.0',
        ],
        'missing' => ['Color'],
        'property_map' => [
            'FontColor' => 'Color',
            'BasePaletteColor' => 'Color',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'IconStyle'],
    ],
    [
        'key' => 'modern_number_input',
        'match' => [
            '#^modernnumberinput(?:@|$)#i',
            '#^numberinput@0\\.0\\.#i',
            '#numberinputcanvas#i',
            '#powerapps_corecontrols_numberinput#i',
        ],
        'yaml' => 'Classic/TextInput@2.3.2',
        'json' => [
            'Id' => 'http://microsoft.com/appmagic/text',
            'Name' => 'text',
            'Version' => '2.3.2',
        ],
        'missing' => ['Fill', 'Color'],
        'property_map' => [
            'FontColor' => 'Color',
            'FontSize' => 'Size',
            'Value' => 'Default',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'TriggerOutput', 'ValidationState', 'Value', 'Min', 'Max', 'Step', 'Precision', 'FontSize'],
    ],
];
