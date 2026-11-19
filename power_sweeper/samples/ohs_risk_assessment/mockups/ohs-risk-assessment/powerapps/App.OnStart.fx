// App.OnStart — VCDS Group OHS Risk Assessment
// Paste the whole block. Run the app once so OnStart fires.
// Components need Access app scope On to see these.

// --- Shape ---
Set(gblRadius, 8);
Set(gblRadiusPill, 20);
Set(gblNavWidth, 216);

// --- Page / chrome ---
Set(gblColorPage, ColorValue("#EFEBE3"));
Set(gblColorPaper, ColorValue("#FBFAF7"));
Set(gblColorInk, ColorValue("#172026"));
Set(gblColorMuted, ColorValue("#5A6A72"));
Set(gblColorLine, ColorValue("#D9D2C6"));
Set(gblColorOnAccent, Color.White);

// --- Brand / nav ---
Set(gblColorNav, ColorValue("#0B1C28"));
Set(gblColorNav2, ColorValue("#132736"));
Set(gblColorNavLine, ColorValue("#243847"));
Set(gblColorNavActive, ColorValue("#1A3340"));
Set(gblColorNavText, ColorValue("#C9D6DC"));
Set(gblColorNavMuted, ColorValue("#8AA0AB"));
Set(gblColorNavTitle, ColorValue("#F6F4EF"));
Set(gblColorAvatar, ColorValue("#1E4A4A"));
Set(gblColorAvatarInk, ColorValue("#D7EFE8"));

// --- Actions ---
Set(gblColorPrimary, ColorValue("#0E746C"));
Set(gblColorPrimary2, ColorValue("#14958C"));
Set(gblColorDanger, ColorValue("#C8102E"));
Set(gblColorQuiet, ColorValue("#E8E2D6"));
Set(gblColorBadge, ColorValue("#C45C26"));
Set(gblColorChip, ColorValue("#F3F0E8"));
Set(gblColorBarTrack, ColorValue("#EBE6DC"));
Set(gblColorProgMark, ColorValue("#E8F1EE"));
Set(gblColorBanner, ColorValue("#E7F1EE"));
Set(gblColorBannerInk, ColorValue("#1D4D3D"));
Set(gblColorBannerWarn, ColorValue("#F8EFE2"));
Set(gblColorBannerWarnInk, ColorValue("#6B3F12"));
Set(gblColorBannerWarnLine, ColorValue("#EAD3B0"));
Set(gblColorClosed, ColorValue("#E7F3EC"));
Set(gblColorClosedLine, ColorValue("#C5DDCF"));

// --- P × S grid fills ---
Set(gblColorRiskLow, ColorValue("#BFE4CF"));
Set(gblColorRiskModerate, ColorValue("#F3D48A"));
Set(gblColorRiskHigh, ColorValue("#F0A57A"));
Set(gblColorRiskExtreme, ColorValue("#E07A7A"));

// --- Score pill fills + ink (table / inbox / review) ---
Set(gblColorScoreLow, ColorValue("#D9EFE3"));
Set(gblColorScoreModerate, ColorValue("#F8E7C2"));
Set(gblColorScoreHigh, ColorValue("#F8D3C2"));
Set(gblColorScoreExtreme, ColorValue("#F4C9C9"));
Set(gblColorRiskLowInk, ColorValue("#1D6B43"));
Set(gblColorRiskModerateInk, ColorValue("#B45309"));
Set(gblColorRiskHighInk, ColorValue("#C2410C"));
Set(gblColorRiskExtremeInk, ColorValue("#9B1C1C"));

// --- Session ---
Set(varL2, "All");
Set(varProgram, "All");
Set(varHighOnly, false);
Set(varRisk, Blank());
Set(varHighlightP, 4);
Set(varHighlightS, 3);
Set(varInboxCount, 4);
Set(varUserDisplay, "L1 Safety Advisor");
Set(varUserRole, "VCDS Group HQ");
Set(varControlLevel, 5);

// --- 5×5 cells (galRiskCells.Items) ---
ClearCollect(
    colRiskCells,
    ForAll(
        Sequence(25) As i,
        With(
            {
                p: 6 - RoundUp(i.Value / 5, 0),
                s: If(Mod(i.Value, 5) = 0, 5, Mod(i.Value, 5))
            },
            {
                Probability: p,
                Severity: s,
                Score: p * s
            }
        )
    )
);

ClearCollect(
    colFilters,
    [
        {Kind: "L2", Value: "All L2s"},
        {Kind: "L2", Value: "VCDS Group HQ"},
        {Kind: "L2", Value: "CFSG (O-G)"},
        {Kind: "L2", Value: "CJCR Gp"},
        {Kind: "L2", Value: "CANELEMNORAD"},
        {Kind: "Program", Value: "General Safety"},
        {Kind: "Program", Value: "WHVP"},
        {Kind: "Program", Value: "Hazardous substances"},
        {Kind: "Level", Value: "High + Extreme"}
    ]
);

ClearCollect(
    colHierarchy,
    [
        {Level: 1, Title: "Elimination"},
        {Level: 2, Title: "Substitution"},
        {Level: 3, Title: "Engineering"},
        {Level: 4, Title: "Administrative"},
        {Level: 5, Title: "PPE"}
    ]
);
