<?php

declare(strict_types=1);

/**
 * Generate a canvas-app-shaped tree with thousands of German/EU locale-corrupted formulas
 * in Src YAML and classic Controls JSON (InvariantScript), simulating Studio language-switch damage.
 *
 * Usage:
 *   php samples/locale_german_corrupt/generate.php
 *   php samples/locale_german_corrupt/generate.php --screens=25 --controls=40
 */

$opts = [
    'screens' => 20,
    'controls' => 40,
];
foreach ($argv as $arg) {
    if (preg_match('/^--screens=(\d+)$/', $arg, $m)) {
        $opts['screens'] = max(1, (int) $m[1]);
    }
    if (preg_match('/^--controls=(\d+)$/', $arg, $m)) {
        $opts['controls'] = max(1, (int) $m[1]);
    }
}

$root = __DIR__;
$srcDir = $root . '/Src';
$controlsDir = $root . '/Controls';

foreach ([$srcDir, $controlsDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

$formulaCount = 0;

/**
 * @return array<string, string>
 */
function corruptionTemplates(int $i, string $controlName): array
{
    $n1 = 10 + ($i % 90);
    $n2 = ($i % 9) + 1;
    $amt = number_format(1000 + $i * 3.25, 2, ',', '.');
    $x = 20 + ($i % 50);
    $y = 40 + ($i % 30);
    $w = 120 + ($i % 40);

    return [
        'Fill' => sprintf('=RGBA(%d; %d; %d; 1)', 240 - ($i % 40), 244 - ($i % 30), 248 - ($i % 20)),
        'Color' => sprintf('=RGBA(%d; %d; %d; 1)', 15 + ($i % 20), 23 + ($i % 20), 42 + ($i % 20)),
        'BorderColor' => sprintf('=RGBA(%d; %d; %d; 1)', 148 + ($i % 40), 163, 184),
        'HoverFill' => sprintf('=RGBA(%d; %d; %d; 1)', 226, 232, 240 - ($i % 10)),
        'PressedFill' => '=RGBA(203; 213; 225; 1)',
        'X' => sprintf('=%d,%d', $x, $n2),
        'Y' => sprintf('=%d,%d', $y, ($i % 5) + 1),
        'Width' => sprintf('=%d,%d', $w, ($i % 3) + 1),
        'Height' => sprintf('=%d,%d', 36, ($i % 4) + 1),
        'OnSelect' => sprintf(
            '=Set(var_%s; %d,%d);; Notify("Gespeichert %s"; NotificationType.Success);; Navigate(Screen1; ScreenTransition.Fade)',
            $controlName,
            $n1,
            $n2,
            $controlName
        ),
        'OnChange' => sprintf(
            '=If(Value(Self.Text) > %d,%d; Set(gblOk; true); Set(gblOk; false));; Collect(colLog; {Name: "%s"; Amount: %s})',
            $n1,
            $n2,
            $controlName,
            $amt
        ),
        'Text' => sprintf(
            '="Betrag: " & Text(%s; "0,00") & " / " & If(%d,%d > 5; "hoch"; "niedrig")',
            $amt,
            $n1,
            $n2
        ),
        'Default' => sprintf('=Text(%s; "0,00")', $amt),
        'Visible' => sprintf('=If(gblReady; %d,%d > 0; false)', $n1, $n2),
        'DisplayMode' => sprintf(
            '=If(LookUp(Requests; ID = %d; Status) = "Open"; DisplayMode.Edit; DisplayMode.View)',
            ($i % 200) + 1
        ),
    ];
}

function writeYamlFormula(string &$yaml, string $prop, string $formula, string $indent = '          '): void
{
    $needsBlock = str_contains($formula, ':') || str_contains($formula, '#') || str_contains($formula, "\n");
    if ($needsBlock) {
        $yaml .= "{$indent}{$prop}: |-\n";
        $yaml .= $indent . '  ' . $formula . "\n";
        return;
    }
    $yaml .= "{$indent}{$prop}: {$formula}\n";
}

file_put_contents($srcDir . '/App.pa.yaml', <<<'YAML'
App:
  Control: App@1.0.0
  Properties:
    StartScreen: =Screen1
    OnStart: |-
      =Set(gblReady; true);; Set(gblLang; "de-DE");; Set(gblThreshold; 12,5);; ClearCollect(colLog; {Name: "init"; Amount: 0})
YAML
);
$formulaCount += 1;

for ($s = 1; $s <= $opts['screens']; $s++) {
    $screenName = 'Screen' . $s;
    $yaml = $screenName . ":\n";
    $yaml .= "  Control: Screen@2.0.0\n";
    $yaml .= "  Properties:\n";
    writeYamlFormula($yaml, 'Fill', '=RGBA(255; 255; 255; 1)', '    ');
    writeYamlFormula(
        $yaml,
        'OnVisible',
        "=Set(gblScreen; {$s});; Set(gblTick; 0,5);; Notify(\"Screen {$s}\"; NotificationType.Information)",
        '    '
    );
    $yaml .= "  Children:\n";
    $formulaCount += 2;

    $jsonChildren = [];

    for ($c = 1; $c <= $opts['controls']; $c++) {
        $controlName = sprintf('Ctl_S%d_C%d', $s, $c);
        $kind = $c % 5;
        $type = match ($kind) {
            0 => 'Label@2.0.0',
            1 => 'Classic/Button@2.0.0',
            2 => 'Classic/TextInput@2.0.0',
            3 => 'Classic/Icon@2.0.0',
            default => 'GroupContainer@1.0.0',
        };

        $templates = corruptionTemplates($c + $s * 17, $controlName);
        $props = match ($kind) {
            0 => ['Fill', 'Color', 'BorderColor', 'X', 'Y', 'Width', 'Height', 'Text', 'Visible'],
            1 => ['Fill', 'Color', 'BorderColor', 'HoverFill', 'PressedFill', 'X', 'Y', 'Width', 'Height', 'OnSelect', 'Text'],
            2 => ['Fill', 'Color', 'BorderColor', 'HoverFill', 'X', 'Y', 'Width', 'Height', 'OnChange', 'Default'],
            3 => ['Fill', 'Color', 'BorderColor', 'X', 'Y', 'Width', 'Height', 'OnSelect'],
            default => ['Fill', 'BorderColor', 'X', 'Y', 'Width', 'Height', 'OnSelect', 'Visible'],
        };

        $yaml .= "    - {$controlName}:\n";
        $yaml .= "        Control: {$type}\n";
        $yaml .= "        Properties:\n";
        if ($kind === 4) {
            $yaml .= "          DropShadow: =DropShadow.Regular\n";
        }

        $rules = [];
        foreach ($props as $prop) {
            if (!isset($templates[$prop])) {
                continue;
            }
            $formula = $templates[$prop];
            writeYamlFormula($yaml, $prop, $formula);
            $formulaCount++;

            $rules[] = [
                'Property' => $prop,
                'Category' => in_array($prop, ['OnSelect', 'OnChange', 'OnVisible', 'OnStart'], true) ? 'Behavior' : 'Design',
                'InvariantScript' => ltrim($formula, '='),
                'RuleProviderType' => 'Unknown',
            ];
        }

        $jsonChildren[] = [
            'Name' => $controlName,
            'Template' => [
                'Name' => match ($kind) {
                    0 => 'label',
                    1 => 'button',
                    2 => 'text',
                    3 => 'icon',
                    default => 'groupContainer',
                },
            ],
            'Rules' => $rules,
        ];
    }

    file_put_contents($srcDir . '/' . $screenName . '.pa.yaml', $yaml);

    $jsonDoc = [
        'TopParent' => [
            'Name' => $screenName,
            'Template' => ['Name' => 'screen'],
            'Rules' => [
                [
                    'Property' => 'Fill',
                    'Category' => 'Design',
                    'InvariantScript' => 'RGBA(255; 255; 255; 1)',
                    'RuleProviderType' => 'Unknown',
                ],
                [
                    'Property' => 'OnVisible',
                    'Category' => 'Behavior',
                    'InvariantScript' => "Set(gblScreen; {$s});; Set(gblTick; 0,5);; Notify(\"Screen {$s}\"; NotificationType.Information)",
                    'RuleProviderType' => 'Unknown',
                ],
            ],
            'Children' => $jsonChildren,
        ],
    ];
    $formulaCount += 2;
    foreach ($jsonChildren as $child) {
        $formulaCount += count($child['Rules']);
    }

    file_put_contents(
        $controlsDir . '/' . $screenName . '.json',
        json_encode($jsonDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
}

$meta = [
    'screens' => $opts['screens'],
    'controls_per_screen' => $opts['controls'],
    'approx_corrupted_formulas' => $formulaCount,
    'corruption' => [
        'decimal' => 'comma (de-DE)',
        'list_separator' => 'semicolon',
        'chaining' => 'double-semicolon',
        'surfaces' => ['Src/*.pa.yaml', 'Controls/*.json InvariantScript'],
    ],
];
file_put_contents($root . '/manifest.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "Generated {$opts['screens']} screens × {$opts['controls']} controls\n";
echo "Approx corrupted formulas (YAML + internal JSON): {$formulaCount}\n";
echo "Wrote Src/ and Controls/ under {$root}\n";
