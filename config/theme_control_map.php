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
 * - optional: skipped unless hop option enable_<key> is true
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
        ],
        'remove' => ['Appearance', 'BasePaletteColor', 'FontColor', 'IconStyle', 'Layout'],
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
        ],
        'remove' => ['FontColor', 'Appearance', 'BasePaletteColor'],
    ],
    [
        'key' => 'modern_text_input',
        'match' => [
            '#^moderntextinput(?:@|$)#i',
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
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'TriggerOutput', 'ValidationState'],
    ],
    [
        'key' => 'modern_dropdown',
        'match' => [
            '#^moderndropdown(?:@|$)#i',
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
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'ValidationState'],
    ],
    [
        'key' => 'modern_combobox',
        'match' => [
            '#^moderncombobox(?:@|$)#i',
            '#^combobox@0\\.0\\.#i',
            '#powerapps_corecontrols_comboboxcanvas#i',
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
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'ValidationState'],
    ],
    [
        'key' => 'modern_datepicker',
        'match' => [
            '#^moderndatepicker(?:@|$)#i',
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
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'ValidationState'],
    ],
    [
        'key' => 'modern_checkbox',
        'match' => [
            '#^checkbox@0\\.0\\.#i',
            '#powerapps_corecontrols_checkboxcanvas#i',
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
            'BasePaletteColor' => 'Fill',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor'],
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
        // Number inputs use .Value instead of .Text — opt-in only.
        'key' => 'modern_number_input',
        'match' => [
            '#^modernnumberinput(?:@|$)#i',
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
            'Value' => 'Default',
        ],
        'remove' => ['Appearance', 'FontColor', 'BasePaletteColor', 'TriggerOutput', 'ValidationState', 'Value', 'Min', 'Max', 'Step', 'Precision'],
        'optional' => true,
    ],
];
