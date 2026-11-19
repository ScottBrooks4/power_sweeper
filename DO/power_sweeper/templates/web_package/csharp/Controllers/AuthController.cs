using System.Net;
using System.Text.Json;

public static class AuthController
{
    public static bool TryHandle(HttpListenerContext ctx, string root, string path)
    {
        if (!path.StartsWith("/auth", StringComparison.Ordinal))
        {
            return false;
        }
        var action = path.TrimEnd('/').Split('/').LastOrDefault() ?? "login";
        var cfg = LoadConfig(root);
        var auth = cfg.TryGetProperty("auth", out var a) ? a : default;

        if (action is "logout")
        {
            GraphModel.SignOut(root);
            Redirect(ctx, "/");
            return true;
        }

        if (action is "callback")
        {
            GraphModel.SignIn(root, new Dictionary<string, string>
            {
                ["name"] = ctx.Request.QueryString["name"] ?? "Demo M365 User",
                ["preferred_username"] = ctx.Request.QueryString["email"] ?? "demo.user@forces.gc.ca",
                ["oid"] = ctx.Request.QueryString["oid"] ?? "demo-user",
                ["tid"] = auth.ValueKind == JsonValueKind.Object && auth.TryGetProperty("tenant", out var t)
                    ? t.GetString() ?? "common" : "common",
            });
            Redirect(ctx, "/");
            return true;
        }

        var mode = auth.ValueKind == JsonValueKind.Object && auth.TryGetProperty("mode", out var m)
            ? (m.GetString() ?? "demo").ToLowerInvariant() : "demo";
        var clientId = auth.ValueKind == JsonValueKind.Object && auth.TryGetProperty("client_id", out var c)
            ? c.GetString() ?? "" : "";
        var tenant = auth.ValueKind == JsonValueKind.Object && auth.TryGetProperty("tenant", out var ten)
            ? ten.GetString() ?? "common" : "common";

        if (mode == "live" && clientId.Length > 0)
        {
            var redirectUri = Uri.EscapeDataString("http://localhost:8080/auth/callback");
            var scope = Uri.EscapeDataString("openid profile email User.Read Mail.Send");
            var url = $"https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize"
                + $"?client_id={clientId}&response_type=code&redirect_uri={redirectUri}"
                + $"&response_mode=query&scope={scope}";
            Redirect(ctx, url);
            return true;
        }

        GraphModel.SignIn(root, new Dictionary<string, string>
        {
            ["name"] = "Demo M365 User",
            ["preferred_username"] = "demo.user@forces.gc.ca",
            ["oid"] = "demo-user",
            ["tid"] = tenant,
        });
        Redirect(ctx, "/");
        return true;
    }

    static JsonElement LoadConfig(string root)
    {
        var path = Path.Combine(root, "server", "config.json");
        if (!File.Exists(path))
        {
            return default;
        }
        using var doc = JsonDocument.Parse(File.ReadAllText(path));
        return doc.RootElement.Clone();
    }

    static void Redirect(HttpListenerContext ctx, string location)
    {
        ctx.Response.StatusCode = 302;
        ctx.Response.RedirectLocation = location;
        ctx.Response.Close();
    }
}
