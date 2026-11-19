using System.Text.Json;

public static class GraphModel
{
    public static object Status(string root)
    {
        var cfg = LoadConfig(root);
        var graph = Prop(cfg, "graph");
        var auth = Prop(cfg, "auth");
        var user = CurrentUser(root);
        return new
        {
            ok = true,
            mode = Str(graph, "mode", "outbox"),
            tenant = Str(auth, "tenant", ""),
            client_id = Str(auth, "client_id", ""),
            scopes = new[]
            {
                "User.Read", "Mail.Send", "Calendars.ReadWrite",
                "Files.ReadWrite.All", "Sites.ReadWrite.All",
            },
            service_account = new
            {
                enabled = true,
                mode = "app_only",
            },
            signed_in = user != null,
            user,
        };
    }

    public static Dictionary<string, string>? CurrentUser(string root)
    {
        var path = Path.Combine(root, "data", "session.json");
        if (!File.Exists(path))
        {
            return null;
        }
        using var doc = JsonDocument.Parse(File.ReadAllText(path));
        if (!doc.RootElement.TryGetProperty("user", out var u) || u.ValueKind != JsonValueKind.Object)
        {
            return null;
        }
        var map = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var p in u.EnumerateObject())
        {
            map[p.Name] = p.Value.ToString();
        }
        return map;
    }

    public static object SignIn(string root, Dictionary<string, string> claims)
    {
        var user = new Dictionary<string, string>
        {
            ["id"] = claims.GetValueOrDefault("oid") ?? "demo-user",
            ["displayName"] = claims.GetValueOrDefault("name") ?? "Demo M365 User",
            ["mail"] = claims.GetValueOrDefault("preferred_username")
                ?? claims.GetValueOrDefault("mail")
                ?? "demo.user@forces.gc.ca",
            ["tenant"] = claims.GetValueOrDefault("tid") ?? "common",
            ["auth"] = "oidc_demo",
        };
        Directory.CreateDirectory(Path.Combine(root, "data"));
        File.WriteAllText(
            Path.Combine(root, "data", "session.json"),
            JsonSerializer.Serialize(new { user }, new JsonSerializerOptions { WriteIndented = true }) + "\n");
        return new { ok = true, user };
    }

    public static object SignOut(string root)
    {
        var path = Path.Combine(root, "data", "session.json");
        if (File.Exists(path))
        {
            File.Delete(path);
        }
        return new { ok = true };
    }

    public static object SendMail(string root, Dictionary<string, JsonElement>? body)
    {
        var to = Str(body, "to");
        var subject = Str(body, "subject");
        var mailBody = Str(body, "body");
        var record = new
        {
            ok = true,
            delivery = "outbox",
            connector = "Microsoft.Graph",
            operation = "sendMail",
            to,
            subject,
            body = mailBody,
        };
        var outbox = Path.Combine(root, "data", "outbox");
        Directory.CreateDirectory(outbox);
        File.WriteAllText(Path.Combine(outbox, "last.json"), JsonSerializer.Serialize(record, new JsonSerializerOptions { WriteIndented = true }));
        return record;
    }

    public static object Calendar(string root, Dictionary<string, JsonElement>? body)
        => new { ok = true, source = "graph.calendar", mode = "outbox", events = Array.Empty<object>(), note = "Wire graph.mode=live for tenant calendar." };

    public static object Files(string root, Dictionary<string, JsonElement>? body)
        => new { ok = true, source = "graph.drive", mode = "outbox", files = Array.Empty<object>(), note = "Wire graph.mode=live for OneDrive/SharePoint." };

    public static object ServiceAccount(string root, Dictionary<string, JsonElement>? body)
        => new
        {
            ok = true,
            enabled = true,
            mode = "app_only",
            action = Str(body, "action", "token"),
            resource = Str(body, "resource", "https://graph.microsoft.com"),
            note = "Non-interactive client-credentials token for SharePoint / CRM sync.",
        };

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

    static JsonElement Prop(JsonElement el, string name)
        => el.ValueKind == JsonValueKind.Object && el.TryGetProperty(name, out var p) ? p : default;

    static string Str(JsonElement el, string name, string fallback = "")
        => el.ValueKind == JsonValueKind.Object && el.TryGetProperty(name, out var p)
            ? p.GetString() ?? fallback : fallback;

    static string Str(Dictionary<string, JsonElement>? body, string name, string fallback = "")
    {
        if (body != null && body.TryGetValue(name, out var p))
        {
            return p.ValueKind == JsonValueKind.String ? p.GetString() ?? fallback : p.ToString();
        }
        return fallback;
    }
}
