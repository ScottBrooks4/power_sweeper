using System.Text;
using System.Text.RegularExpressions;

public static class ScreenController
{
    public static byte[]? TryRender(string root, string? screen = null)
    {
        var index = Path.Combine(root, "public", "index.html");
        if (!File.Exists(index))
        {
            return TryViewFile(root, screen);
        }

        var html = File.ReadAllText(index);
        if (!string.IsNullOrEmpty(screen))
        {
            var safe = System.Net.WebUtility.HtmlEncode(screen);
            html = Regex.Replace(html, @"data-start-screen=""[^""]*""", $"data-start-screen=\"{safe}\"", RegexOptions.None, TimeSpan.FromSeconds(1));
            html = Regex.Replace(html, @"\sscreen--active", "", RegexOptions.None, TimeSpan.FromSeconds(1));
            html = Regex.Replace(
                html,
                $@"(<section\b[^>]*\bdata-screen=""{Regex.Escape(safe)}""[^>]*)\bclass=""([^""]*)""",
                @"$1 class=""$2 screen--active""",
                RegexOptions.None,
                TimeSpan.FromSeconds(1));
        }

        if (!html.Contains("mvc-auth-chip", StringComparison.Ordinal))
        {
            var user = GraphModel.CurrentUser(root);
            string chip;
            if (user == null)
            {
                chip = """<a class="mvc-auth-chip" href="auth/login">Sign in with Microsoft</a>""";
            }
            else
            {
                var label = System.Net.WebUtility.HtmlEncode(
                    user.GetValueOrDefault("displayName")
                    ?? user.GetValueOrDefault("mail")
                    ?? "Signed in");
                chip = $"""<span class="mvc-auth-chip mvc-auth-chip--in">{label} · <a href="auth/logout">Sign out</a></span>""";
            }
            html = html.Replace("<body>", "<body>\n  " + chip, StringComparison.Ordinal);
        }

        return Encoding.UTF8.GetBytes(html);
    }

    static byte[]? TryViewFile(string root, string? screen)
    {
        if (string.IsNullOrEmpty(screen))
        {
            return null;
        }
        var safe = Regex.Replace(screen, @"[^\w.-]", "_");
        if (safe.Length == 0)
        {
            return null;
        }
        var path = Path.Combine(root, "views", safe + ".html");
        return File.Exists(path) ? File.ReadAllBytes(path) : null;
    }
}
