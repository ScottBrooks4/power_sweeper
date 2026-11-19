<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ControlNode;
use PowerSweeper\Report;

/**
 * VCR-only copy that rides along with the language sweep: hover notes on
 * sections 6, 9 and 10, and an editable IVCO-sites notice (same store as the
 * email templates) with Continue and Cancel.
 */
final class VcrLanguageProfile
{
    private const PAGE = 'http://intranet.mil.ca/en/health-safety-security/security-vcr-outside-canada-london.page';

    /** @param array<string, array{en:string,fr:string}> $packs */
    public static function preparePacks(array &$packs): void
    {
        $packs['VisitDateHint'] = [
            'en' => 'Please include the total duration of your visit, including your travel dates.',
            'fr' => 'Veuillez fournir la durée totale de votre visite, y compris les dates de voyage aller et retour.',
        ];
        $packs['ClearanceProofHint'] = [
            'en' => 'Your proof of security documentation must include a digital signature/stamp from either your USS/DUSS verifying your clearance.',
            'fr' => 'La preuve de votre habilitation de sécurité doit inclure une signature ou un cachet numérique de votre USS/DUSS confirmant votre habilitation.',
        ];
        $packs['RemarksHint'] = [
            'en' => 'If applicable, please provide justification for the late submission. In addition, please outline any expired security clearance(s), including when renewal was requested, along with any further remarks that may be relevant to this visit.',
            'fr' => 'Le cas échéant, veuillez justifier la soumission tardive de cette demande. De plus, veuillez fournir les détails de toute habilitation de sécurité expirée, y compris la date de la demande de renouvellement, ainsi que toute information ou remarque complémentaire pertinente à cette visite.',
        ];
    }

    /**
     * @param list<\PowerSweeper\ControlDocument> $documents
     */
    /** @param array<string, array{en:string,fr:string}> $packs */
    public static function apply(array $documents, array $packs, string $langVar, bool $langIsBoolean, Report $report): void
    {
        if (!self::isVcrApp($documents)) {
            return;
        }
        $isEn = $langIsBoolean ? $langVar : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN")';
        self::applyHoverNotes($documents, $packs, $isEn, $report);
        self::addInstructionIcons($documents, $isEn, $report);
        self::seedNoticeTemplate($documents, $report);
        self::addAdminEditor($documents, $isEn, $report);
        self::addHomePopup($documents, $isEn, $report);
        self::bindSectionMenus($documents, $isEn, $report);
        self::persistLanguage($documents, $langVar, $langIsBoolean, $report);
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function isVcrApp(array $documents): bool
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name === 'NewRequestWholeButtonClassic') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    /** @param array<string, array{en:string,fr:string}> $packs */
    private static function applyHoverNotes(array $documents, array $packs, string $isEn, Report $report): void
    {
        $map = [
            '6. date of visit' => 'VisitDateHint',
            'section 6: date of visit' => 'VisitDateHint',
            'proof of security clearance:' => 'ClearanceProofHint',
            '10. remarks' => 'RemarksHint',
        ];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->format !== 'yaml') {
                    continue;
                }
                $text = trim((string) preg_replace('/\s+/u', ' ', self::englishOf((string) ($control->getProperty('Text') ?? ''), $packs)));
                $token = $map[mb_strtolower($text)] ?? null;
                if ($token === null) {
                    continue;
                }
                $expr = 'If(' . $isEn . ', gblStrings.' . $token . '.En, gblStrings.' . $token . '.Fr)';
                $before = (string) ($control->getProperty('Tooltip') ?? '');
                $control->setProperty('Tooltip', '=' . $expr);
                $report->add('translate', $control->path, 'Tooltip', $before !== '' ? $before : '(empty)', '=' . $expr);
            }
        }
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function seedNoticeTemplate(array $documents, Report $report): void
    {
        $seed = 'If(IsBlank(LookUp(varTempMessages, email = "ivco_notice_en")), Collect(varTempMessages, {email: "ivco_notice_en", content: "'
            . self::fx(self::noticeHtml(true)) . '"}, {email: "ivco_notice_fr", content: "'
            . self::fx(self::noticeHtml(false)) . '"}))';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if (!str_contains($raw, 'email = "ack_deny"') || str_contains($raw, 'ivco_notice_en')) {
                        continue;
                    }
                    // Only the settings refresh seeds templates. The same key
                    // also appears on the admin editor Default and Submit.
                    if (!str_contains($raw, 'ParseJSON(a.Messages)')) {
                        continue;
                    }
                    $body = rtrim(rtrim(ltrim(trim($raw), '=')), ";\n\r\t ");
                    $control->setProperty($prop, '=' . $body . ";\n" . $seed);
                    $report->add('translate', $control->path, $prop, '(messages seed)', 'ivco_notice_en/fr');
                }
            }
        }
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function addAdminEditor(array $documents, string $isEn, Report $report): void
    {
        // The IVCO sites notice stays on New Request. It does not get an admin button.
        unset($documents, $isEn, $report);
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function addHomePopup(array $documents, string $isEn, Report $report): void
    {
        $fallback = 'If(' . $isEn . ', "' . self::fx(self::noticeHtml(true)) . '", "' . self::fx(self::noticeHtml(false)) . '")';
        $html = 'Coalesce(LookUp(varTempMessages, email = If(' . $isEn . ', "ivco_notice_en", "ivco_notice_fr")).content, ' . $fallback . ')';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $button) {
                if ($button->name !== 'NewRequestWholeButtonClassic') {
                    continue;
                }
                $onSelect = (string) ($button->getProperty('OnSelect') ?? '');
                if (!str_contains($onSelect, 'varShowIvcoNotice')) {
                    $button->setProperty('OnSelect', '=Set(varShowIvcoNotice, true)');
                    $report->add('translate', $button->path, 'OnSelect', $onSelect, '=Set(varShowIvcoNotice, true)');
                } else {
                    $onSelect = '';
                }
                $screen = self::screenFor($documents, $button);
                if ($screen === null || $screen->format !== $button->format) {
                    continue;
                }
                $continue = $onSelect !== '' ? ltrim(trim($onSelect), '=') : 'Set(varShowIvcoNotice, false)';
                $continue = 'Set(varShowIvcoNotice, false); ' . $continue;
                self::insertPopup($screen, $isEn, $html, $continue, $report);
            }
        }
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $app) {
                if (!$app->isApp()) {
                    continue;
                }
                $start = (string) ($app->getProperty('OnStart') ?? '');
                if ($start === '' || str_contains($start, 'varShowIvcoNotice')) {
                    continue;
                }
                $body = rtrim(rtrim(ltrim(trim($start), '=')), ";\n\r\t ");
                $app->setProperty('OnStart', '=' . $body . ";\nSet(varShowIvcoNotice, false); Set(varPsInfoOpen, false);");
            }
        }
    }

    private static function insertPopup(ControlNode $screen, string $isEn, string $html, string $continue, Report $report): void
    {
        if ($screen->format === 'json') {
            $screen->addJsonChild(self::jsonGroup('psIvcoNotice', $screen->name, [
                'Fill' => 'RGBA(0, 0, 0, 0.45)',
                'Height' => 'Parent.Height',
                'Visible' => 'varShowIvcoNotice',
                'Width' => 'Parent.Width',
                'X' => '0',
                'Y' => '0',
                'ZIndex' => '1000',
            ], [
                self::jsonControl('psIvcoScrim', 'psIvcoNotice', 'http://microsoft.com/appmagic/button', 'button', '2.2.0', self::scrimButton('Set(varShowIvcoNotice, false)')),
                self::jsonGroup('psIvcoCard', 'psIvcoNotice', [
                    'Fill' => 'gblTheme.Surface',
                    'BorderColor' => 'gblTheme.BorderStrong',
                    'BorderThickness' => '1',
                    'RadiusTopLeft' => '8',
                    'RadiusTopRight' => '8',
                    'RadiusBottomLeft' => '8',
                    'RadiusBottomRight' => '8',
                    'Height' => 'Parent.Height - 24',
                    'Width' => 'Parent.Width - 40',
                    'X' => '(Parent.Width - Self.Width) / 2',
                    'Y' => '(Parent.Height - Self.Height) / 2',
                    'OnSelect' => 'false',
                    'ZIndex' => '2',
                ], [
                    self::jsonControl('psIvcoHtml', 'psIvcoCard', 'http://microsoft.com/appmagic/htmlViewer', 'htmlViewer', '2.1.0', [
                        'HtmlText' => $html,
                        'Fill' => 'gblTheme.Surface',
                        'Color' => 'gblTheme.Text',
                        'Font' => "Font.'Segoe UI'",
                        'Size' => '14',
                        'AutoHeight' => 'false',
                        'PaddingTop' => '20',
                        'PaddingBottom' => '8',
                        'PaddingLeft' => '28',
                        'PaddingRight' => '28',
                        'X' => '0',
                        'Y' => '0',
                        'Width' => 'Parent.Width',
                        'Height' => 'Parent.Height - 72',
                    ]),
                    self::themedButton('psIvcoCancel', 'psIvcoCard', 'If(' . $isEn . ', "Cancel", "Annuler")', 'Set(varShowIvcoNotice, false)', '(Parent.Width / 2) - 176', 'Parent.Height - 60', false),
                    self::themedButton('psIvcoContinue', 'psIvcoCard', 'If(' . $isEn . ', "Continue", "Continuer")', $continue, '(Parent.Width / 2) + 16', 'Parent.Height - 60', true),
                ]),
            ]));
            $report->add('translate', $screen->path, 'Children', '(none)', 'psIvcoNotice');

            return;
        }
        $screen->addYamlChild('psIvcoNotice', [
            'Control' => 'GroupContainer@1.5.0',
            'Variant' => 'ManualLayout',
            'Properties' => [
                'DropShadow' => '=DropShadow.None',
                'Fill' => '=RGBA(0, 0, 0, 0.45)',
                'Height' => '=Parent.Height',
                'Visible' => '=varShowIvcoNotice',
                'Width' => '=Parent.Width',
                'X' => '=0',
                'Y' => '=0',
            ],
            'Children' => [
                [
                    'psIvcoScrim' => [
                        'Control' => 'Classic/Button@2.2.0',
                        'Properties' => [
                            'BorderColor' => '=RGBA(0, 0, 0, 0)',
                            'BorderThickness' => '=0',
                            'Color' => '=RGBA(0, 0, 0, 0)',
                            'Fill' => '=RGBA(0, 0, 0, 0)',
                            'HoverFill' => '=RGBA(0, 0, 0, 0)',
                            'PressedFill' => '=RGBA(0, 0, 0, 0)',
                            'Height' => '=Parent.Height',
                            'OnSelect' => '=Set(varShowIvcoNotice, false)',
                            'Text' => '=',
                            'Width' => '=Parent.Width',
                            'X' => '=0',
                            'Y' => '=0',
                        ],
                    ],
                ],
                [
                    'psIvcoCard' => [
                        'Control' => 'GroupContainer@1.5.0',
                        'Variant' => 'ManualLayout',
                        'Properties' => [
                            'BorderColor' => '=gblTheme.BorderStrong',
                            'BorderThickness' => '=1',
                            'DropShadow' => '=DropShadow.None',
                            'Fill' => '=gblTheme.Surface',
                            'Height' => '=Parent.Height - 24',
                            'OnSelect' => '=false',
                            'RadiusBottomLeft' => '=8',
                            'RadiusBottomRight' => '=8',
                            'RadiusTopLeft' => '=8',
                            'RadiusTopRight' => '=8',
                            'Width' => '=Parent.Width - 40',
                            'X' => '=(Parent.Width - Self.Width) / 2',
                            'Y' => '=(Parent.Height - Self.Height) / 2',
                        ],
                        'Children' => [
                            [
                                'psIvcoHtml' => [
                                    'Control' => 'HtmlViewer@2.1.0',
                                    'Properties' => [
                                        'Color' => '=gblTheme.Text',
                                        'Fill' => '=gblTheme.Surface',
                                        'Font' => "=Font.'Segoe UI'",
                                        'AutoHeight' => '=false',
                                        'Height' => '=Parent.Height - 72',
                                        'HtmlText' => '=' . $html,
                                        'PaddingBottom' => '=8',
                                        'PaddingLeft' => '=28',
                                        'PaddingRight' => '=28',
                                        'PaddingTop' => '=20',
                                        'Size' => '=14',
                                        'Width' => '=Parent.Width',
                                    ],
                                ],
                            ],
                            [
                                'psIvcoCancel' => [
                                    'Control' => 'Classic/Button@2.2.0',
                                    'Properties' => [
                                        'Align' => '=Align.Center',
                                        'BorderColor' => '=gblTheme.BorderStrong',
                                        'BorderThickness' => '=1',
                                        'Color' => '=gblTheme.Text',
                                        'Fill' => '=gblTheme.Surface',
                                        'Font' => "=Font.'Segoe UI'",
                                        'HoverColor' => '=gblTheme.Text',
                                        'HoverFill' => '=gblTheme.Surface',
                                        'Height' => '=40',
                                        'OnSelect' => '=Set(varShowIvcoNotice, false)',
                                        'PressedColor' => '=gblTheme.Text',
                                        'PressedFill' => '=gblTheme.Surface',
                                        'Size' => '=12',
                                        'Text' => '=If(' . $isEn . ', "Cancel", "Annuler")',
                                        'VerticalAlign' => '=VerticalAlign.Middle',
                                        'Width' => '=160',
                                        'X' => '=(Parent.Width / 2) - 176',
                                        'Y' => '=Parent.Height - 60',
                                    ],
                                ],
                            ],
                            [
                                'psIvcoContinue' => [
                                    'Control' => 'Classic/Button@2.2.0',
                                    'Properties' => [
                                        'Align' => '=Align.Center',
                                        'BorderColor' => '=gblTheme.Accent',
                                        'BorderThickness' => '=0',
                                        'Color' => '=gblTheme.TextOnAccent',
                                        'Fill' => '=gblTheme.Accent',
                                        'Font' => "=Font.'Segoe UI'",
                                        'HoverColor' => '=gblTheme.TextOnAccent',
                                        'HoverFill' => '=gblTheme.Accent',
                                        'Height' => '=40',
                                        'OnSelect' => '=' . $continue,
                                        'PressedColor' => '=gblTheme.TextOnAccent',
                                        'PressedFill' => '=gblTheme.Accent',
                                        'Size' => '=12',
                                        'Text' => '=If(' . $isEn . ', "Continue", "Continuer")',
                                        'VerticalAlign' => '=VerticalAlign.Middle',
                                        'Width' => '=160',
                                        'X' => '=(Parent.Width / 2) + 16',
                                        'Y' => '=Parent.Height - 60',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $report->add('translate', $screen->path, 'Children', '(none)', 'psIvcoNotice');
    }

    /**
     * @param list<\PowerSweeper\ControlDocument> $documents
     */
    private static function addInstructionIcons(array $documents, string $isEn, Report $report): void
    {
        $notes = [
            'Date_Label' => ['VisitDateHint', 'Date of visit', 'Date de la visite'],
            'USSPKILabel' => ['ClearanceProofHint', 'Proof of security clearance', 'Preuve de cote de sécurité'],
            '10_Header_1' => ['RemarksHint', 'Remarks', 'Remarques'],
        ];
        $screens = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $parent) {
                foreach ($parent->children as $child) {
                    $note = $notes[$child->name] ?? null;
                    if ($note === null || $parent->format !== $child->format) {
                        continue;
                    }
                    [$token, $enTitle, $frTitle] = $note;
                    $icon = 'psInfo_' . $child->name;
                    $onSelect = 'Set(varPsInfoOpen, true); Set(varPsInfoTitle, If(' . $isEn . ', "' . $enTitle . '", "' . $frTitle . '")); Set(varPsInfoBody, If(' . $isEn . ', gblStrings.' . $token . '.En, gblStrings.' . $token . '.Fr))';
                    if ($parent->format === 'json') {
                        $parent->addJsonChild(self::jsonControl($icon, $parent->name, 'http://microsoft.com/appmagic/icon', 'icon', '2.5.0', [
                            'Icon' => 'Icon.Information',
                            'OnSelect' => $onSelect,
                            'Height' => '18',
                            'Width' => '18',
                            'Color' => 'gblTheme.Accent',
                        ]));
                    } else {
                        $parent->addYamlChild($icon, [
                            'Control' => 'Classic/Icon@2.5.0',
                            'Properties' => [
                                'BorderColor' => '=RGBA(0, 0, 0, 0)',
                                'Color' => '=gblTheme.Accent',
                                'Height' => '=18',
                                'Icon' => '=Icon.Information',
                                'OnSelect' => '=' . $onSelect,
                                'Width' => '=18',
                            ],
                        ]);
                    }
                    $report->add('translate', $parent->path, 'Children', '(none)', $icon);
                    $screen = self::screenFor($documents, $child);
                    if ($screen !== null) {
                        $screens[$screen->path] = $screen;
                    }
                }
            }
        }
        foreach ($screens as $screen) {
            self::insertInfoPopup($screen, $isEn, $report);
        }
    }

    private static function insertInfoPopup(ControlNode $screen, string $isEn, Report $report): void
    {
        if ($screen->format === 'json') {
            $screen->addJsonChild(self::jsonGroup('psInfoPopup', $screen->name, [
                'Fill' => 'RGBA(0, 0, 0, 0.45)',
                'Height' => 'Parent.Height',
                'Visible' => 'varPsInfoOpen',
                'Width' => 'Parent.Width',
                'X' => '0',
                'Y' => '0',
                'ZIndex' => '1000',
            ], [
                self::jsonControl('psInfoScrim', 'psInfoPopup', 'http://microsoft.com/appmagic/button', 'button', '2.2.0', self::scrimButton('Set(varPsInfoOpen, false)')),
                self::jsonGroup('psInfoCard', 'psInfoPopup', [
                    'Fill' => 'gblTheme.Surface',
                    'BorderColor' => 'gblTheme.BorderStrong',
                    'BorderThickness' => '1',
                    'OnSelect' => 'false',
                    'ZIndex' => '2',
                    'Height' => '168',
                    'Width' => '440',
                    'X' => '(Parent.Width - Self.Width) / 2',
                    'Y' => '(Parent.Height - Self.Height) / 2',
                ], [
                    self::jsonControl('psInfoTitle', 'psInfoCard', 'http://microsoft.com/appmagic/label', 'label', '2.5.1', [
                        'Text' => 'varPsInfoTitle',
                        'Color' => 'gblTheme.Text',
                        'Fill' => 'gblTheme.Surface',
                        'DisabledColor' => 'gblTheme.Text',
                        'Font' => "Font.'Segoe UI'",
                        'FontWeight' => 'FontWeight.Semibold',
                        'Align' => 'Align.Left',
                        'VerticalAlign' => 'VerticalAlign.Middle',
                        'BorderThickness' => '0',
                        'Size' => '14',
                        'Height' => '36',
                        'Width' => 'Parent.Width - 48',
                        'X' => '16',
                        'Y' => '12',
                    ]),
                    self::jsonControl('psInfoClose', 'psInfoCard', 'http://microsoft.com/appmagic/label', 'label', '2.5.1', [
                        'Text' => '"×"',
                        'OnSelect' => 'Set(varPsInfoOpen, false)',
                        'Align' => 'Align.Center',
                        'VerticalAlign' => 'VerticalAlign.Middle',
                        'Color' => 'gblTheme.Text',
                        'Fill' => 'RGBA(0, 0, 0, 0)',
                        'HoverFill' => 'RGBA(0, 0, 0, 0)',
                        'Font' => "Font.'Segoe UI'",
                        'Size' => '18',
                        'BorderThickness' => '0',
                        'Height' => '32',
                        'Width' => '32',
                        'X' => 'Parent.Width - 40',
                        'Y' => '6',
                    ]),
                    self::jsonControl('psInfoBody', 'psInfoCard', 'http://microsoft.com/appmagic/label', 'label', '2.5.1', [
                        'Text' => 'varPsInfoBody',
                        'Color' => 'gblTheme.Text',
                        'Fill' => 'gblTheme.Surface',
                        'DisabledColor' => 'gblTheme.Text',
                        'Font' => "Font.'Segoe UI'",
                        'Align' => 'Align.Left',
                        'VerticalAlign' => 'VerticalAlign.Top',
                        'BorderThickness' => '0',
                        'Wrap' => 'true',
                        'Size' => '12',
                        'Height' => 'Parent.Height - 52',
                        'Width' => 'Parent.Width - 32',
                        'X' => '16',
                        'Y' => '48',
                        'PaddingTop' => '4',
                        'PaddingLeft' => '0',
                        'PaddingRight' => '8',
                    ]),
                ]),
            ]));
            $report->add('translate', $screen->path, 'Children', '(none)', 'psInfoPopup');

            return;
        }
        $screen->addYamlChild('psInfoPopup', [
            'Control' => 'GroupContainer@1.5.0',
            'Variant' => 'ManualLayout',
            'Properties' => [
                'DropShadow' => '=DropShadow.None',
                'Fill' => '=RGBA(0, 0, 0, 0.45)',
                'Height' => '=Parent.Height',
                'Visible' => '=varPsInfoOpen',
                'Width' => '=Parent.Width',
                'X' => '=0',
                'Y' => '=0',
            ],
            'Children' => [
                [
                    'psInfoCard' => [
                        'Control' => 'GroupContainer@1.5.0',
                        'Variant' => 'ManualLayout',
                        'Properties' => [
                            'DropShadow' => '=DropShadow.None',
                            'Fill' => '=gblTheme.Surface',
                            'Height' => '=168',
                            'Width' => '=440',
                            'X' => '=(Parent.Width - Self.Width) / 2',
                            'Y' => '=(Parent.Height - Self.Height) / 2',
                        ],
                        'Children' => [
                            [
                                'psInfoTitle' => [
                                    'Control' => 'Label@2.5.1',
                                    'Properties' => [
                                        'Align' => '=Align.Left',
                                        'BorderThickness' => '=0',
                                        'Color' => '=gblTheme.Text',
                                        'DisabledColor' => '=gblTheme.Text',
                                        'Fill' => '=gblTheme.Surface',
                                        'Font' => "=Font.'Segoe UI'",
                                        'FontWeight' => '=FontWeight.Semibold',
                                        'Height' => '=36',
                                        'Size' => '=14',
                                        'Text' => '=varPsInfoTitle',
                                        'VerticalAlign' => '=VerticalAlign.Middle',
                                        'Width' => '=Parent.Width - 48',
                                        'X' => '=16',
                                        'Y' => '=12',
                                    ],
                                ],
                            ],
                            [
                                'psInfoClose' => [
                                    'Control' => 'Label@2.5.1',
                                    'Properties' => [
                                        'Align' => '=Align.Center',
                                        'BorderThickness' => '=0',
                                        'Color' => '=gblTheme.Text',
                                        'Fill' => '=RGBA(0, 0, 0, 0)',
                                        'Font' => "=Font.'Segoe UI'",
                                        'Height' => '=32',
                                        'OnSelect' => '=Set(varPsInfoOpen, false)',
                                        'Size' => '=18',
                                        'Text' => '="×"',
                                        'VerticalAlign' => '=VerticalAlign.Middle',
                                        'Width' => '=32',
                                        'X' => '=Parent.Width - 40',
                                        'Y' => '=6',
                                    ],
                                ],
                            ],
                            [
                                'psInfoBody' => [
                                    'Control' => 'Label@2.5.1',
                                    'Properties' => [
                                        'Align' => '=Align.Left',
                                        'BorderThickness' => '=0',
                                        'Color' => '=gblTheme.Text',
                                        'DisabledColor' => '=gblTheme.Text',
                                        'Fill' => '=gblTheme.Surface',
                                        'Font' => "=Font.'Segoe UI'",
                                        'Height' => '=Parent.Height - 52',
                                        'PaddingLeft' => '=0',
                                        'PaddingRight' => '=8',
                                        'PaddingTop' => '=4',
                                        'Size' => '=12',
                                        'Text' => '=varPsInfoBody',
                                        'VerticalAlign' => '=VerticalAlign.Top',
                                        'Width' => '=Parent.Width - 32',
                                        'Wrap' => '=true',
                                        'X' => '=16',
                                        'Y' => '=48',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $report->add('translate', $screen->path, 'Children', '(none)', 'psInfoPopup');
    }

    /** @return array<string, string> */
    private static function scrimButton(string $onSelect): array
    {
        return [
            'Text' => '""',
            'OnSelect' => $onSelect,
            'Fill' => 'RGBA(0, 0, 0, 0)',
            'HoverFill' => 'RGBA(0, 0, 0, 0)',
            'PressedFill' => 'RGBA(0, 0, 0, 0)',
            'Color' => 'RGBA(0, 0, 0, 0)',
            'HoverColor' => 'RGBA(0, 0, 0, 0)',
            'PressedColor' => 'RGBA(0, 0, 0, 0)',
            'BorderColor' => 'RGBA(0, 0, 0, 0)',
            'BorderThickness' => '0',
            'X' => '0',
            'Y' => '0',
            'Width' => 'Parent.Width',
            'Height' => 'Parent.Height',
            'ZIndex' => '1',
        ];
    }

    private static function themedButton(string $name, string $parent, string $text, string $onSelect, string $x, string $y, bool $accent): object
    {
        $fill = $accent ? 'gblTheme.Accent' : 'gblTheme.Surface';
        $color = $accent ? 'gblTheme.TextOnAccent' : 'gblTheme.Text';
        $border = $accent ? 'gblTheme.Accent' : 'gblTheme.BorderStrong';

        return self::jsonControl($name, $parent, 'http://microsoft.com/appmagic/button', 'button', '2.2.0', [
            'Text' => $text,
            'OnSelect' => $onSelect,
            'Align' => 'Align.Center',
            'VerticalAlign' => 'VerticalAlign.Middle',
            'Font' => "Font.'Segoe UI'",
            'Size' => '12',
            'Fill' => $fill,
            'HoverFill' => $fill,
            'PressedFill' => $fill,
            'Color' => $color,
            'HoverColor' => $color,
            'PressedColor' => $color,
            'BorderColor' => $border,
            'BorderThickness' => $accent ? '0' : '1',
            'RadiusTopLeft' => '4',
            'RadiusTopRight' => '4',
            'RadiusBottomLeft' => '4',
            'RadiusBottomRight' => '4',
            'X' => $x,
            'Y' => $y,
            'Width' => '160',
            'Height' => '40',
        ]);
    }

    /** @param array<string, string> $props @param list<object> $children */
    private static function jsonGroup(string $name, string $parent, array $props, array $children): object
    {
        return self::jsonControl($name, $parent, 'http://microsoft.com/appmagic/groupContainer', 'groupContainer', '1.5.0', $props, $children, 'manualLayoutContainer');
    }

    /** @param array<string, string> $props @param list<object> $children */
    private static function jsonControl(string $name, string $parent, string $id, string $template, string $version, array $props, array $children = [], string $variant = ''): object
    {
        $rules = [];
        foreach ($props as $property => $script) {
            $category = 'Design';
            if (in_array($property, ['OnSelect', 'OnTimerEnd', 'OnChange', 'OnVisible'], true)) {
                $category = 'Behavior';
            } elseif (in_array($property, ['Text', 'Tooltip', 'AccessibleLabel', 'HtmlText', 'Items'], true)) {
                $category = 'Data';
            }
            $rules[] = (object) [
                'Property' => $property,
                'Category' => $category,
                'InvariantScript' => $script,
                'RuleProviderType' => 'User',
            ];
        }
        $styles = [
            'groupContainer' => 'defaultGroupContainerStyle',
            'label' => 'defaultLabelStyle',
            'button' => 'defaultButtonStyle',
            'rectangle' => 'defaultRectangleStyle',
            'htmlViewer' => 'defaultHtmlViewerStyle',
            'icon' => 'defaultIconStyle',
            'timer' => 'defaultTimerStyle',
        ];
        $node = (object) [
            'Type' => 'ControlInfo',
            'Name' => $name,
            'HasDynamicProperties' => false,
            'Template' => (object) [
                'Id' => $id,
                'Version' => $version,
                'LastModifiedTimestamp' => '0',
                'Name' => $template,
                'FirstParty' => true,
                'IsPremiumPcfControl' => false,
                'IsCustomGroupControlTemplate' => false,
                'CustomGroupControlTemplateName' => '',
                'IsComponentDefinition' => false,
                'OverridableProperties' => (object) [],
            ],
            'VariantName' => $variant,
            'Parent' => $parent,
            'StyleName' => $styles[$template] ?? '',
            'IsDataControl' => false,
            'AllowAccessToGlobals' => true,
            'Rules' => $rules,
            'ControlPropertyState' => array_map(
                static fn (string $property): object => (object) [
                    'InvariantPropertyName' => $property,
                    'AutoRuleBindingEnabled' => false,
                    'AutoRuleBindingString' => '',
                    'NameMapSourceSchema' => '?',
                    'IsLockable' => false,
                    'AFDDataSourceName' => '',
                ],
                array_keys($props)
            ),
            'Children' => $children,
        ];

        return $node;
    }

    private static function saveFormula(): string
    {
        return <<<'FX'
=With(
    { en: LookUp(varTempMessages, email = "ivco_notice_en"), fr: LookUp(varTempMessages, email = "ivco_notice_fr") },
    If(IsBlank(en), Collect(varTempMessages, { email: "ivco_notice_en", content: IvcoNoticeEn.HtmlText }), Patch(varTempMessages, en, { content: IvcoNoticeEn.HtmlText }));
    If(IsBlank(fr), Collect(varTempMessages, { email: "ivco_notice_fr", content: IvcoNoticeFr.HtmlText }), Patch(varTempMessages, fr, { content: IvcoNoticeFr.HtmlText }))
);
Set(colMessages, varTempMessages);
Patch('CDLS (L) App Settings', LookUp('CDLS (L) App Settings', Title = "1"), { Messages: JSON(varTempMessages) });
Notify(If(varLang, "IVCO sites notice saved.", "Avis des sites IVCO enregistré."), NotificationType.Success)
FX;
    }

    private static function noticeHtml(bool $english): string
    {
        $url = self::PAGE;
        if ($english) {
            return '<p>Thank you for using CDLS(L) VCR / VCN Form for completing your request to the UK, Ireland and British Overseas Territories (BOTs).</p>'
                . '<p>To comply with UK (host nation) procedures and regulations, please complete the <a href="' . $url . '"><u>VCR Form</u></a> ONLY if you are visiting any of the sites listed below, or if you are a member of a military or government organisation visiting an industry facility in the UK at the Confidential level or above.</p>'
                . '<p><strong>Sites:</strong></p><ul>'
                . '<li>Atomic Weapons Establishment (AWE)</li>'
                . '<li>Defence Geographic Centre (DGC) – Feltham</li>'
                . '<li>PJHQ / Northwood</li>'
                . '<li>JFIG / Pathfinder building Only – RAF Wyton</li>'
                . '<li>QinetiQ</li></ul>'
                . '<p>If you are NOT visiting any of the mentioned sites or you are travelling to Ireland or a British Overseas Territory, please continue.</p>';
        }

        return '<p>Merci d’utiliser le formulaire VCR / VCN de CDLS(L) pour soumettre votre demande de visite au Royaume-Uni, en Irlande ou dans les Territoires britanniques d’outre-mer (BOTs).</p>'
            . '<p>Afin de respecter les procédures et réglementations du Royaume-Uni (nation hôte), veuillez remplir le <a href="' . $url . '"><u>formulaire VCR</u></a> UNIQUEMENT si vous visitez l’un des sites indiqués ci-dessous, ou si vous représentez une organisation militaire ou gouvernementale effectuant une visite dans une installation industrielle au Royaume-Uni au niveau de classification Confidentiel ou supérieur.</p>'
            . '<p><strong>Sites concernés :</strong></p><ul>'
            . '<li>Atomic Weapons Establishment (AWE)</li>'
            . '<li>Defence Geographic Centre (DGC) – Feltham</li>'
            . '<li>PJHQ / Northwood</li>'
            . '<li>JFIG / Bâtiment Pathfinder uniquement – RAF Wyton</li>'
            . '<li>QinetiQ</li></ul>'
            . '<p>Si vous NE VISITEZ PAS l’un des sites mentionnés ci-dessus, ou si vous vous rendez en Irlande ou dans un Territoire britannique d’outre-mer, veuillez poursuivre votre demande sans remplir ce formulaire.</p>';
    }

    /**
     * Remember each user's language on App Users, and keep a French copy of
     * every email template inside the existing App Settings Messages JSON.
     *
     * @param list<\PowerSweeper\ControlDocument> $documents
     */
    private static function persistLanguage(array $documents, string $langVar, bool $langIsBoolean, Report $report): void
    {
        self::patchUserLanguageFormulas($documents, $langVar, $langIsBoolean, $report);
        self::patchLanguageToggles($documents, $langVar, $langIsBoolean, $report);
        self::addLocalizedEmailFormula($documents, $langVar, $langIsBoolean, $report);
        self::rewriteEmailLookups($documents, $report);
        self::seedFrenchEmailTemplates($documents, $report);
        self::addFrenchTemplateEditors($documents, $report);
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function patchUserLanguageFormulas(array $documents, string $langVar, bool $langIsBoolean, Report $report): void
    {
        // Do not read PreferredLanguage inside loadAppUsers. A missing column
        // makes that whole formula fail, which clears varCurrentUser and hides Admin.
        unset($documents, $langVar, $langIsBoolean, $report);
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function patchLanguageToggles(array $documents, string $langVar, bool $langIsBoolean, Report $report): void
    {
        foreach ($documents as $doc) {
            $doc->reindex();
        }
        // Slider hits already Set(varLang) and move the knob. Do not append
        // Select(psLangPersist) there: a missing PreferredLanguage column makes
        // that call an authoring error and the click never runs.
        $snippet = 'Set(varPsLangKnobX, If(' . $langVar . ', 2, 46))';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $prop = null;
                if ($control->name === 'psLangENHit' || $control->name === 'psLangFRHit') {
                    continue;
                }
                $onChange = (string) ($control->getProperty('OnChange') ?? '');
                if (str_contains($onChange, 'Selected.Value = "English"') && str_contains($onChange, $langVar)) {
                    $prop = 'OnChange';
                }
                if ($prop === null) {
                    continue;
                }
                $before = (string) ($control->getProperty($prop) ?? '');
                if (str_contains($before, 'PreferredLanguage:')) {
                    continue;
                }
                $control->appendStatement($prop, $snippet);
                $report->add('translate', $control->path, $prop, $before !== '' ? $before : '(empty)', $snippet);
            }
        }
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function addLocalizedEmailFormula(array $documents, string $langVar, bool $langIsBoolean, Report $report): void
    {
        $french = $langIsBoolean
            ? '!' . $langVar
            : 'Or(Lower(' . $langVar . ') = "fr", Lower(' . $langVar . ') = "french")';
        $fn = "fnLocalizedEmail(key: Text): Text = Coalesce(If(" . $french . ", Coalesce(LookUp(colMessages, email = key & \"_fr\").content, LookUp(varTempMessages, email = key & \"_fr\").content)), LookUp(colMessages, email = key).content, LookUp(varTempMessages, email = key).content);";
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isApp()) {
                    continue;
                }
                $raw = (string) ($control->getProperty('Formulas') ?? '');
                if ($raw === '' || str_contains($raw, 'fnLocalizedEmail')) {
                    continue;
                }
                $body = rtrim(rtrim(ltrim(trim($raw), '=')), ";\n\r\t ");
                $control->setProperty('Formulas', '=' . $body . ";\n" . $fn);
                $report->add('translate', $control->path, 'Formulas', '(email helper)', 'fnLocalizedEmail');
            }
        }
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function rewriteEmailLookups(array $documents, Report $report): void
    {
        $pattern = '/Coalesce\s*\(\s*LookUp\s*\(\s*colMessages\s*,\s*email\s*=\s*("[\w]+"|locKey)\s*\)\s*\.content\s*,\s*LookUp\s*\(\s*varTempMessages\s*,\s*email\s*=\s*\1\s*\)\s*\.content\s*\)/';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if ($raw === '' || !str_contains($raw, 'colMessages')) {
                        continue;
                    }
                    $next = preg_replace($pattern, 'fnLocalizedEmail($1)', $raw);
                    if (!is_string($next) || $next === $raw) {
                        continue;
                    }
                    $control->setProperty($prop, $next);
                    $report->add('translate', $control->path, $prop, '(email lookup)', 'fnLocalizedEmail');
                }
            }
        }
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function seedFrenchEmailTemplates(array $documents, Report $report): void
    {
        $loaded = require POWER_SWEEPER_ROOT . '/config/vcr_email_french.php';
        if (!is_array($loaded)) {
            return;
        }
        /** @var array<string, string> $templates */
        $templates = $loaded;
        $lines = [];
        foreach ($templates as $key => $html) {
            if (!is_string($key) || !is_string($html) || $html === '') {
                continue;
            }
            $content = self::fx($html);
            $lines[] = 'With({ en: LookUp(varTempMessages, email = "' . $key . '"), fr: LookUp(varTempMessages, email = "' . $key . '_fr") }, If(IsBlank(fr), Collect(varTempMessages, {email: "' . $key . '_fr", content: "' . $content . '"}), If(!IsBlank(en) && fr.content = en.content, Patch(varTempMessages, fr, {content: "' . $content . '"}))))';
        }
        $lines[] = 'Set(colMessages, varTempMessages)';
        $seed = implode(";\n", $lines);
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if (!str_contains($raw, 'email = "ack_deny"') || str_contains($raw, 'sub_conf_fr')) {
                        continue;
                    }
                    if (!str_contains($raw, 'ParseJSON(a.Messages)')) {
                        continue;
                    }
                    $body = self::removeDeniedFallback(rtrim(rtrim(ltrim(trim($raw), '=')), ";\n\r\t "));
                    $control->setProperty($prop, '=' . $body . ";\n" . $seed);
                    $report->add('translate', $control->path, $prop, '(french templates)', 'sub_conf_fr');
                }
            }
        }
    }

    /**
     * Denial was the only template with a hardcoded English body. The others
     * are read from App Settings, so the fallback is removed.
     */
    private static function removeDeniedFallback(string $raw): string
    {
        $marker = 'has been denied';
        $at = strpos($raw, $marker);
        if ($at === false) {
            return $raw;
        }
        $blank = strrpos(substr($raw, 0, $at), 'IsBlank(LookUp(varTempMessages, email = "ack_deny"))');
        if ($blank === false) {
            return $raw;
        }
        $ifPos = strrpos(substr($raw, 0, $blank), 'If(');
        if ($ifPos === false) {
            return $raw;
        }
        $close = self::scanClose($raw, $ifPos + 2);
        if ($close === null) {
            return $raw;
        }
        $end = $close + 1;
        if (isset($raw[$end]) && $raw[$end] === ';') {
            $end++;
        }

        return rtrim(substr($raw, 0, $ifPos)) . substr($raw, $end);
    }

    private static function scanClose(string $source, int $openParen): ?int
    {
        $depth = 0;
        $length = strlen($source);
        $inString = false;
        for ($i = $openParen; $i < $length; $i++) {
            $char = $source[$i];
            if ($inString) {
                if ($char === '"') {
                    if ($i + 1 < $length && $source[$i + 1] === '"') {
                        $i++;
                        continue;
                    }
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Section tabs read ThisItem.Label, which is stored when the collection is
     * built. Bind the tab text to the stable Key so it follows the language toggle.
     *
     * @param list<\PowerSweeper\ControlDocument> $documents
     */
    private static function bindSectionMenus(array $documents, string $isEn, Report $report): void
    {
        unset($isEn);
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isApp()) {
                    continue;
                }
                $raw = (string) ($control->getProperty('OnStart') ?? '');
                if ($raw === '' || str_contains($raw, 'colFormSectionsFr')) {
                    continue;
                }
                $next = self::duplicateSectionCollection($raw, 'colFormSectionsWithoutEmergency', 'colFormSectionsWithoutEmergencyFr');
                $next = self::duplicateSectionCollection($next, 'colFormSections', 'colFormSectionsFr');
                if ($next !== $raw) {
                    $control->setProperty('OnStart', $next);
                    $report->add('translate', $control->path, 'OnStart', '(section menu)', 'colFormSectionsFr');
                }
            }
        }
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $items = (string) ($control->getProperty('Items') ?? '');
                if (!str_contains($items, 'colFormSections') || str_contains($items, 'colFormSectionsFr')) {
                    continue;
                }
                $body = ltrim(trim($items), '=');
                if (str_contains($body, 'fnIsAcknowledged')) {
                    $nextBody = 'If(varLang, If(fnIsAcknowledged, colFormSections, colFormSectionsWithoutEmergency), If(fnIsAcknowledged, colFormSectionsFr, colFormSectionsWithoutEmergencyFr))';
                } else {
                    $nextBody = 'If(varLang, colFormSections, colFormSectionsFr)';
                }
                $prefix = str_starts_with(trim($items), '=') || $control->format === 'yaml' ? '=' : '';
                $control->setProperty('Items', $prefix . $nextBody);
                $display = (string) ($control->getProperty('ItemDisplayText') ?? '');
                if (str_contains($display, 'ThisItem.Key') || ($display !== '' && !str_contains($display, 'ThisItem.Label'))) {
                    $control->setProperty('ItemDisplayText', $prefix . 'ThisItem.Label');
                }
                $default = (string) ($control->getProperty('Default') ?? '');
                if ($default !== '' && str_contains($default, 'colFormSections') && !str_contains($default, 'colFormSectionsFr')) {
                    $patched = str_replace('colFormSectionsWithoutEmergency', '§NOEMER§', $default);
                    $patched = str_replace('colFormSections', 'If(varLang, colFormSections, colFormSectionsFr)', $patched);
                    $patched = str_replace('§NOEMER§', 'If(varLang, colFormSectionsWithoutEmergency, colFormSectionsWithoutEmergencyFr)', $patched);
                    $control->setProperty('Default', $patched);
                }
                $report->add('translate', $control->path, 'Items', $items, $prefix . $nextBody);
            }
        }
    }

    private static function duplicateSectionCollection(string $body, string $name, string $frenchName): string
    {
        if (str_contains($body, $frenchName)) {
            return $body;
        }
        $offset = 0;
        while (($pos = strpos($body, 'ClearCollect(', $offset)) !== false) {
            $open = strpos($body, '(', $pos);
            if ($open === false) {
                return $body;
            }
            if (preg_match('/^\s*' . preg_quote($name, '/') . '\s*,/', substr($body, $open + 1)) !== 1) {
                $offset = $pos + 12;
                continue;
            }
            $close = self::scanClose($body, $open);
            if ($close === null) {
                return $body;
            }
            $block = substr($body, $pos, $close - $pos + 1);
            $copy = preg_replace('/\b' . preg_quote($name, '/') . '\b/', $frenchName, $block, 1) ?? $block;
            $copy = self::frenchSectionLabels($copy);
            $end = $close + 1;
            if (isset($body[$end]) && $body[$end] === ';') {
                $end++;
            }

            return substr($body, 0, $end) . "\n" . $copy . ';' . substr($body, $end);
        }

        return $body;
    }

    private static function frenchSectionLabels(string $block): string
    {
        $map = [
            'Destination' => 'Destination',
            'Organization' => 'Organisation',
            'Visit type' => 'Type de visite',
            'Classification' => 'Classification',
            'Summary' => 'Résumé',
            'Dates' => 'Dates',
            'Initiative' => 'Initiative',
            'Pertinence' => 'Pertinence',
            'Security officer' => 'Officier de sécurité',
            'Remarks' => 'Remarques',
            'Sites' => 'Sites',
            'Visitors' => 'Visiteurs',
            'Emergency' => 'Urgence',
        ];
        $out = preg_replace_callback(
            '/\bLabel\s*:\s*"((?:[^"]|"")*)"/',
            static function (array $match) use ($map): string {
                $en = str_replace('""', '"', $match[1]);
                $fr = $map[$en] ?? $en;

                return 'Label: "' . str_replace('"', '""', $fr) . '"';
            },
            $block
        );

        return is_string($out) ? $out : $block;
    }

    /** @return array<string, string> */
    private static function templateLangLabelProps(string $text): array
    {
        $props = self::templateLangLabelRules($text);
        $out = [];
        foreach ($props as $property => $script) {
            $out[$property] = '=' . $script;
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function templateLangLabelRules(string $text): array
    {
        return [
            'Align' => 'Align.Left',
            'BorderThickness' => '0',
            'Color' => 'gblTheme.Text',
            'DisabledColor' => 'gblTheme.Text',
            'Fill' => 'RGBA(0, 0, 0, 0)',
            'Font' => "Font.'Open Sans'",
            'FontWeight' => 'FontWeight.Semibold',
            'Height' => '28',
            'LayoutMinHeight' => '28',
            'PaddingLeft' => '0',
            'Size' => '12',
            'Text' => $text,
            'VerticalAlign' => 'VerticalAlign.Middle',
            'Width' => 'Parent.Width',
        ];
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function addFrenchTemplateEditors(array $documents, Report $report): void
    {
        $editors = [
            ['en' => 'VCRSubmissionMessage', 'key' => 'sub_conf', 'before' => 'Container50_1'],
            ['en' => 'ECFSubmissionMessage', 'key' => 'ecf_conf', 'before' => 'Container50_2'],
            ['en' => 'AcknowledgedRequestMessage', 'key' => 'ack_done', 'before' => 'Container50_3'],
            ['en' => 'DeniedRequestMessage', 'key' => 'ack_deny', 'before' => 'Container50_Deny'],
            ['en' => 'AcknowledgmentRequestMessage', 'key' => 'ack_notif', 'before' => 'Container50_4'],
            ['en' => 'IVCOMessage', 'key' => 'ivco', 'before' => 'Container50_5'],
        ];
        foreach ($editors as $editor) {
            $fr = $editor['en'] . 'Fr';
            foreach ($documents as $doc) {
                foreach ($doc->controls() as $control) {
                    if ($control->name !== $editor['en']) {
                        continue;
                    }
                    $parent = self::parentOfNode($documents, $control);
                    if ($parent === null || $parent->format !== $control->format) {
                        continue;
                    }
                    if ($control->format === 'yaml') {
                        $parent->addYamlChild($editor['en'] . 'LangLabel', [
                            'Control' => 'Label@2.5.1',
                            'Properties' => self::templateLangLabelProps('"English"'),
                        ], $editor['en']);
                        $parent->addYamlChild($fr . 'Label', [
                            'Control' => 'Label@2.5.1',
                            'Properties' => self::templateLangLabelProps('"Français"'),
                        ], $editor['before']);
                        $parent->addYamlChild($fr, [
                            'Control' => 'RichTextEditor@2.7.0',
                            'Properties' => [
                                'BorderColor' => '=RGBA(0, 18, 107, 1)',
                                'Default' => '=LookUp(varTempMessages, email = "' . $editor['key'] . '_fr").content',
                                'FillPortions' => '=1',
                                'LayoutMinHeight' => '=120',
                                'LayoutMinWidth' => '=16',
                                'Width' => '=Parent.Width',
                            ],
                        ], $editor['before']);
                    } else {
                        $parent->addJsonChild(
                            self::jsonControl(
                                $editor['en'] . 'LangLabel',
                                $parent->name,
                                'http://microsoft.com/appmagic/label',
                                'label',
                                '2.5.1',
                                self::templateLangLabelRules('"English"')
                            ),
                            $editor['en']
                        );
                        $label = self::jsonControl(
                            $fr . 'Label',
                            $parent->name,
                            'http://microsoft.com/appmagic/label',
                            'label',
                            '2.5.1',
                            self::templateLangLabelRules('"Français"')
                        );
                        $copy = $control->cloneJsonControl($fr);
                        if ($copy !== null) {
                            self::setJsonRule($copy, 'Default', 'LookUp(varTempMessages, email = "' . $editor['key'] . '_fr").content');
                            $parent->addJsonChild($copy, $editor['before']);
                        }
                        $parent->addJsonChild($label, $fr);
                    }
                    $report->add('translate', $parent->path, 'Children', $editor['en'], $fr);
                }
            }
        }
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if ($raw === '') {
                        continue;
                    }
                    $next = $raw;
                    foreach ($editors as $editor) {
                        $fr = $editor['en'] . 'Fr';
                        $key = $editor['key'];
                        if (
                            !str_contains($next, $key . '_fr')
                            && str_contains($next, 'email = "' . $key . '"')
                            && str_contains($next, $editor['en'] . '.HtmlText')
                        ) {
                            $block = 'With({ idFr: LookUp(varTempMessages, email = "' . $key . '_fr") }, If(IsBlank(idFr), Collect(varTempMessages, { email: "' . $key . '_fr", content: ' . $fr . '.HtmlText }), Patch(varTempMessages, idFr, { content: ' . $fr . '.HtmlText }))); ';
                            $replaced = preg_replace('/Set\s*\(\s*colMessages/', $block . 'Set(colMessages', $next, 1);
                            if (is_string($replaced)) {
                                $next = $replaced;
                            }
                        }
                        $reset = 'Reset(' . $editor['en'] . ')';
                        $resetFr = 'Reset(' . $fr . ')';
                        if (str_contains($next, $reset) && !str_contains($next, $resetFr)) {
                            $next = str_replace($reset, $reset . '; ' . $resetFr, $next);
                        }
                    }
                    if ($next !== $raw) {
                        $control->setProperty($prop, $next);
                        $report->add('translate', $control->path, $prop, '(french template save)', 'key_fr');
                    }
                }
            }
        }
    }

    private static function setJsonRule(object $node, string $property, string $script): void
    {
        if (!isset($node->Rules) || !is_array($node->Rules)) {
            $node->Rules = [];
        }
        foreach ($node->Rules as $rule) {
            if (is_object($rule) && (string) ($rule->Property ?? '') === $property) {
                $rule->InvariantScript = $script;

                return;
            }
        }
        $node->Rules[] = (object) [
            'Property' => $property,
            'Category' => 'Data',
            'InvariantScript' => $script,
            'RuleProviderType' => 'Unknown',
        ];
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function parentOfNode(array $documents, ControlNode $child): ?ControlNode
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->format !== $child->format) {
                    continue;
                }
                foreach ($control->children as $candidate) {
                    if ($candidate->name === $child->name && $candidate->path === $child->path) {
                        return $control;
                    }
                }
            }
        }

        return null;
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function parentOf(array $documents, string $childName): ?ControlNode
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->children as $child) {
                    if ($child->name === $childName) {
                        return $control;
                    }
                }
            }
        }

        return null;
    }

    /** @param list<\PowerSweeper\ControlDocument> $documents */
    private static function screenFor(array $documents, ControlNode $node): ?ControlNode
    {
        $best = null;
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $isScreen = $control->isScreen() || str_contains($control->path, '/Screens/');
                if (!$isScreen) {
                    continue;
                }
                if ($control->path !== $node->path && !str_starts_with($node->path, $control->path . '/')) {
                    continue;
                }
                // Screen names can contain slashes ("VCR / VCN Form"), so the
                // screen is the shortest matching ancestor, not a path segment.
                if ($best === null || strlen($control->path) < strlen($best->path)) {
                    $best = $control;
                }
            }
        }

        return $best;
    }

    /** @param array<string, array{en:string,fr:string}> $packs */
    private static function englishOf(string $raw, array $packs): string
    {
        $v = trim(ltrim(trim($raw), '='));
        if (preg_match('/gblStrings\.(\w+)\.En/', $v, $m) === 1 && isset($packs[$m[1]]['en'])) {
            return trim($packs[$m[1]]['en']);
        }

        return self::unwrap($raw);
    }

    private static function unwrap(string $value): string
    {
        $v = trim($value);
        if (str_starts_with($v, '=')) {
            $v = trim(substr($v, 1));
        }
        if (str_starts_with($v, 'If(') && str_contains($v, 'gblStrings')) {
            return '';
        }
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }

        return trim($v);
    }

    private static function fx(string $s): string
    {
        return str_replace(['\\', '"'], ['\\\\', '""'], $s);
    }
}
