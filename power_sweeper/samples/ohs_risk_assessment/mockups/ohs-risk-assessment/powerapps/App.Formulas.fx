// Optional. Paste into App.Formulas (Power Fx named formulas / UDFs).
// Score = Probability (1–5) × Severity (1–5)
// Bands from the VCDS L2 register: Low 1–3, Moderate 4–6, High 8–12, Extreme 15–25.

fxRiskFill(Score: Number): Color =
    If(
        Score <= 3,
        gblColorRiskLow,
        Score <= 6,
        gblColorRiskModerate,
        Score <= 12,
        gblColorRiskHigh,
        gblColorRiskExtreme
    );

fxRiskInk(Score: Number): Color =
    If(
        Score <= 3,
        gblColorRiskLowInk,
        Score <= 6,
        gblColorRiskModerateInk,
        Score <= 12,
        gblColorRiskHighInk,
        gblColorRiskExtremeInk
    );

fxRiskBand(Score: Number): Text =
    If(
        Score <= 3,
        "Low",
        Score <= 6,
        "Moderate",
        Score <= 12,
        "High",
        "Extreme"
    );
