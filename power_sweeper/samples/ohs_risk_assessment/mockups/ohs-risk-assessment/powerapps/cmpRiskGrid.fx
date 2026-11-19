// cmpRiskGrid — canvas component
//
// Custom properties (Input):
//   Mode        Text     "Select" | "Overview"   Default "Select"
//   HighlightP  Number   Default 0
//   HighlightS  Number   Default 0
//
// Custom properties (Output):
//   SelectedP   Number   galRiskCells.Selected.Probability
//   SelectedS   Number   galRiskCells.Selected.Severity
//
// Select  — user taps a cell to set P×S (used on Review / Treat).
// Overview — cell text is the count from colRiskCounts; count 0 is ColorFade(fill, 70%).

// --- btnCell.Text ---
If(cmpRiskGrid.Mode = "Overview", Text(Coalesce(ThisItem.Count, 0)), Text(ThisItem.Score))

// --- btnCell.Fill ---
With(
    {
        base: fxRiskFill(ThisItem.Score),
        empty: cmpRiskGrid.Mode = "Overview" && Coalesce(ThisItem.Count, 0) = 0
    },
    If(empty, ColorFade(base, 70%), base)
)

// --- btnCell.DisplayMode ---
If(cmpRiskGrid.Mode = "Overview", DisplayMode.View, DisplayMode.Edit)
