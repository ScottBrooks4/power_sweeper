<?php

declare(strict_types=1);

/**
 * Power Apps YAML / JSON control templates → web HTML/CSS/JS equivalences.
 *
 * Used by export_web_ir for pixel-faithful emission and by import_web_ir to
 * understand which Power Fx properties map back from the web IR.
 *
 * Each entry:
 * - key: stable id
 * - match: regexes against lowercased Control / Template.Name / Template.Id
 * - html: tag name
 * - role: IR / a11y role
 * - input_type: <input type> when html is input
 * - value_prop: primary Power Apps value property
 * - output_prop: runtime output the web control exposes (Text, SelectedDate, …)
 * - events: web event → Power Apps formula property
 * - css: Power Apps property → CSS property (literals only at emit time)
 * - attrs: Power Apps property → HTML attribute
 *
 * @return list<array<string, mixed>>
 */
return [
    [
        'key' => 'screen',
        'match' => ['#^screen(?:@|$)#i'],
        'html' => 'section',
        'role' => 'screen',
        'css' => ['Fill' => 'background-color'],
    ],
    [
        'key' => 'label',
        'match' => [
            '#^(?:classic/)?label(?:@|$)#i',
            '#^moderntext(?:@|$)#i',
            '#^text@0\\.0\\.#i',
            '#textcanvas#i',
            '#appmagic/label#i',
        ],
        'html' => 'div',
        'role' => 'label',
        'value_prop' => 'Text',
        'output_prop' => 'Text',
        'events' => ['click' => 'OnSelect'],
        'css' => [
            'Fill' => 'background-color',
            'Color' => 'color',
            'FontColor' => 'color',
            'Size' => 'font-size',
            'FontSize' => 'font-size',
            'Align' => 'text-align',
        ],
    ],
    [
        'key' => 'button',
        'match' => [
            '#^(?:classic/)?button(?:@|$)#i',
            '#^modernbutton(?:@|$)#i',
            '#buttoncanvas#i',
            '#appmagic/button#i',
        ],
        'html' => 'button',
        'role' => 'button',
        'value_prop' => 'Text',
        'output_prop' => 'Text',
        'events' => ['click' => 'OnSelect'],
        'css' => [
            'Fill' => 'background-color',
            'Color' => 'color',
            'FontColor' => 'color',
            'Size' => 'font-size',
            'FontSize' => 'font-size',
        ],
    ],
    [
        'key' => 'text_input',
        'match' => [
            '#^(?:classic/)?textinput(?:@|$)#i',
            '#^moderntextinput(?:@|$)#i',
            '#textinputcanvas#i',
            '#appmagic/text$#i',
            '#^text@2\\.#i',
        ],
        'html' => 'input',
        'role' => 'input',
        'input_type' => 'text',
        'value_prop' => 'Default',
        'output_prop' => 'Text',
        'events' => ['input' => 'OnChange', 'change' => 'OnChange'],
        'attrs' => ['HintText' => 'placeholder', 'Placeholder' => 'placeholder'],
        'css' => [
            'Fill' => 'background-color',
            'Color' => 'color',
            'Size' => 'font-size',
            'FontSize' => 'font-size',
        ],
    ],
    [
        'key' => 'number_input',
        'match' => [
            '#^modernnumberinput(?:@|$)#i',
            '#numberinputcanvas#i',
            '#numberinput#i',
        ],
        'html' => 'input',
        'role' => 'input',
        'input_type' => 'number',
        'value_prop' => 'Default',
        'output_prop' => 'Text',
        'events' => ['input' => 'OnChange', 'change' => 'OnChange'],
        'css' => [
            'Fill' => 'background-color',
            'Color' => 'color',
            'Size' => 'font-size',
        ],
    ],
    [
        'key' => 'dropdown',
        'match' => [
            '#^(?:classic/)?dropdown(?:@|$)#i',
            '#^moderndropdown(?:@|$)#i',
            '#dropdowncanvas#i',
            '#appmagic/dropdown#i',
        ],
        'html' => 'select',
        'role' => 'input',
        'value_prop' => 'Default',
        'output_prop' => 'Selected',
        'events' => ['change' => 'OnChange'],
        'css' => [
            'Fill' => 'background-color',
            'Color' => 'color',
            'Size' => 'font-size',
        ],
    ],
    [
        'key' => 'combobox',
        'match' => [
            '#^(?:classic/)?combobox(?:@|$)#i',
            '#^moderncombobox(?:@|$)#i',
            '#comboboxcanvas#i',
            '#appmagic/combobox#i',
        ],
        'html' => 'select',
        'role' => 'input',
        'value_prop' => 'DefaultSelectedItems',
        'output_prop' => 'Selected',
        'events' => ['change' => 'OnChange'],
        'css' => [
            'Fill' => 'background-color',
            'Color' => 'color',
            'Size' => 'font-size',
        ],
    ],
    [
        'key' => 'datepicker',
        'match' => [
            '#^(?:classic/)?datepicker(?:@|$)#i',
            '#^moderndatepicker(?:@|$)#i',
            '#datepickercanvas#i',
            '#appmagic/datepicker#i',
        ],
        'html' => 'input',
        'role' => 'input',
        'input_type' => 'date',
        'value_prop' => 'DefaultDate',
        'output_prop' => 'SelectedDate',
        'events' => ['change' => 'OnChange'],
        'css' => [
            'Fill' => 'background-color',
            'Color' => 'color',
            'Size' => 'font-size',
            'FontSize' => 'font-size',
        ],
    ],
    [
        'key' => 'checkbox',
        'match' => [
            '#^(?:classic/)?checkbox(?:@|$)#i',
            '#^moderncheckbox(?:@|$)#i',
            '#checkboxcanvas#i',
            '#appmagic/checkbox#i',
        ],
        'html' => 'label',
        'role' => 'choice',
        'value_prop' => 'Default',
        'output_prop' => 'Value',
        'events' => ['change' => 'OnCheck'],
        'css' => ['Color' => 'color', 'Size' => 'font-size'],
    ],
    [
        'key' => 'toggle',
        'match' => [
            '#^(?:classic/)?toggle(?:@|$)#i',
            '#^moderntoggle(?:@|$)#i',
            '#togglecanvas#i',
            '#appmagic/toggle#i',
        ],
        'html' => 'label',
        'role' => 'choice',
        'value_prop' => 'Default',
        'output_prop' => 'Value',
        'events' => ['change' => 'OnCheck'],
        'css' => ['Color' => 'color'],
    ],
    [
        'key' => 'radio',
        'match' => [
            '#^(?:classic/)?radio(?:@|$)#i',
            '#^modernradio(?:@|$)#i',
            '#radiogroupcanvas#i',
            '#radiocanvas#i',
            '#appmagic/radio#i',
        ],
        'html' => 'fieldset',
        'role' => 'choice',
        'value_prop' => 'Default',
        'output_prop' => 'Selected',
        'events' => ['change' => 'OnChange'],
        'css' => ['Fill' => 'background-color', 'Color' => 'color', 'Size' => 'font-size'],
    ],
    [
        'key' => 'slider',
        'match' => [
            '#^(?:classic/)?slider(?:@|$)#i',
            '#^modernslider(?:@|$)#i',
            '#slidercanvas#i',
            '#appmagic/slider#i',
        ],
        'html' => 'input',
        'role' => 'input',
        'input_type' => 'range',
        'value_prop' => 'Default',
        'output_prop' => 'Value',
        'events' => ['input' => 'OnChange'],
    ],
    [
        'key' => 'icon',
        'match' => [
            '#^(?:classic/)?icon(?:@|$)#i',
            '#^modernicon(?:@|$)#i',
            '#appmagic/icon#i',
        ],
        'html' => 'button',
        'role' => 'media',
        'value_prop' => 'Icon',
        'events' => ['click' => 'OnSelect'],
        'css' => ['Color' => 'color', 'Fill' => 'background-color'],
    ],
    [
        'key' => 'image',
        'match' => ['#^(?:classic/)?image(?:@|$)#i', '#appmagic/image#i'],
        'html' => 'img',
        'role' => 'media',
        'value_prop' => 'Image',
        'events' => ['click' => 'OnSelect'],
    ],
    [
        'key' => 'html',
        'match' => ['#htmlviewer#i', '#htmltext#i', '#appmagic/html#i'],
        'html' => 'div',
        'role' => 'html',
        'value_prop' => 'HtmlText',
    ],
    [
        'key' => 'gallery',
        'match' => ['#gallery#i', '#appmagic/gallery#i'],
        'html' => 'div',
        'role' => 'gallery',
        'value_prop' => 'Items',
        'output_prop' => 'Selected',
        'events' => ['click' => 'OnSelect'],
        'css' => ['Fill' => 'background-color', 'TemplateFill' => 'background-color'],
    ],
    [
        'key' => 'form',
        'match' => ['#^(?:classic/)?form(?:@|$)#i', '#appmagic/form#i'],
        'html' => 'form',
        'role' => 'form',
        'value_prop' => 'DataSource',
        'events' => ['submit' => 'OnSuccess'],
        'css' => ['Fill' => 'background-color'],
    ],
    [
        'key' => 'container',
        'match' => [
            '#groupcontainer#i',
            '#container#i',
            '#^group(?:@|$)#i',
            '#appmagic/group#i',
        ],
        'html' => 'div',
        'role' => 'container',
        'css' => ['Fill' => 'background-color'],
    ],
    [
        'key' => 'rectangle',
        'match' => ['#rectangle#i', '#shape#i'],
        'html' => 'div',
        'role' => 'media',
        'events' => ['click' => 'OnSelect'],
        'css' => ['Fill' => 'background-color', 'BorderColor' => 'border-color'],
    ],
    [
        'key' => 'circle',
        'match' => ['#circle#i'],
        'html' => 'div',
        'role' => 'media',
        'css' => ['Fill' => 'background-color'],
    ],
    [
        'key' => 'timer',
        'match' => ['#timer#i'],
        'html' => 'div',
        'role' => 'control',
        'events' => ['timer' => 'OnTimerEnd'],
    ],
    [
        'key' => 'datatable',
        'match' => ['#datatable#i'],
        'html' => 'table',
        'role' => 'gallery',
        'value_prop' => 'Items',
        'css' => ['Fill' => 'background-color', 'Color' => 'color'],
    ],
];
