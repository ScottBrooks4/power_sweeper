using System.Net;
using System.Text;
using System.Text.Json;

var root = Directory.GetCurrentDirectory();
if (args.Length >= 2 && args[0] == "store")
{
    var payload = args[1] == "insert"
        ? JsonSerializer.Serialize(new { action = "insert", table = args.ElementAtOrDefault(2) ?? "Items", row = new { Title = args.ElementAtOrDefault(3) ?? "CSharp", Value = args.ElementAtOrDefault(4) ?? "C" } })
        : JsonSerializer.Serialize(new { action = "list", table = args.ElementAtOrDefault(2) ?? "Items" });
    using var doc = JsonDocument.Parse(payload);
    var result = AppStore.Dispatch(root, doc.RootElement);
    Console.WriteLine(JsonSerializer.Serialize(result));
    return;
}

var port = int.TryParse(Environment.GetEnvironmentVariable("PORT"), out var parsed) ? parsed : 8080;
var listener = new HttpListener();
listener.Prefixes.Add($"http://+:{port}/");
try
{
    listener.Start();
}
catch (HttpListenerException)
{
    listener = new HttpListener();
    listener.Prefixes.Add($"http://localhost:{port}/");
    listener.Start();
}
Console.WriteLine($"Power Sweeper web package on http://localhost:{port}");
while (listener.IsListening)
{
    var ctx = listener.GetContext();
    try
    {
        Handle(ctx, root);
    }
    catch (Exception ex)
    {
        WriteJson(ctx, 500, JsonSerializer.Serialize(new { ok = false, error = ex.Message }));
    }
}

static void Handle(HttpListenerContext ctx, string root)
{
    var path = ctx.Request.Url?.AbsolutePath ?? "/";
    var isMvc = LayoutIsMvc(root);

    if (isMvc && AuthController.TryHandle(ctx, root, path))
    {
        return;
    }
    if (isMvc && GraphController.TryHandle(ctx, root, path))
    {
        return;
    }

    if (path == "/api/data")
    {
        using var reader = new StreamReader(ctx.Request.InputStream, ctx.Request.ContentEncoding);
        var raw = reader.ReadToEnd();
        if (string.IsNullOrWhiteSpace(raw))
        {
            raw = ctx.Request.Url?.Query is { Length: > 1 } q
                ? QueryToJson(q)
                : "{}";
        }
        using var doc = JsonDocument.Parse(string.IsNullOrWhiteSpace(raw) ? "{}" : raw);
        WriteJson(ctx, 200, JsonSerializer.Serialize(AppStore.Dispatch(root, doc.RootElement)));
        return;
    }

    var screen = ctx.Request.QueryString["screen"];
    if (isMvc && (path is "/" or "" || !string.IsNullOrEmpty(screen)))
    {
        var view = ScreenController.TryRender(root, screen);
        if (view == null)
        {
            WriteText(ctx, 404, "Unknown screen\n", "text/plain; charset=utf-8");
            return;
        }
        WriteBytes(ctx, 200, view, "text/html; charset=utf-8");
        return;
    }
    if (!string.IsNullOrEmpty(screen))
    {
        var view = ScreenController.TryRender(root, screen);
        if (view == null)
        {
            WriteText(ctx, 404, "Unknown screen\n", "text/plain; charset=utf-8");
            return;
        }
        WriteBytes(ctx, 200, view, "text/html; charset=utf-8");
        return;
    }
    var rel = path == "/" ? "public/index.html" : path.TrimStart('/').StartsWith("ir/", StringComparison.Ordinal)
        ? path.TrimStart('/')
        : Path.Combine("public", path.TrimStart('/'));
    var file = Path.GetFullPath(Path.Combine(root, rel));
    if (!file.StartsWith(Path.GetFullPath(root), StringComparison.Ordinal) || !File.Exists(file))
    {
        WriteText(ctx, 404, "Not found\n", "text/plain; charset=utf-8");
        return;
    }
    WriteBytes(ctx, 200, File.ReadAllBytes(file), ContentType(file));
}

static bool LayoutIsMvc(string root)
{
    var cfg = Path.Combine(root, "server", "config.json");
    if (!File.Exists(cfg))
    {
        return Directory.Exists(Path.Combine(root, "views"));
    }
    try
    {
        using var doc = JsonDocument.Parse(File.ReadAllText(cfg));
        return doc.RootElement.TryGetProperty("layout", out var layout)
            && string.Equals(layout.GetString(), "mvc", StringComparison.OrdinalIgnoreCase);
    }
    catch
    {
        return false;
    }
}

static string QueryToJson(string query)
{
    var map = new Dictionary<string, string>();
    foreach (var part in query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries))
    {
        var bits = part.Split('=', 2);
        map[Uri.UnescapeDataString(bits[0])] = bits.Length > 1 ? Uri.UnescapeDataString(bits[1]) : "";
    }
    return JsonSerializer.Serialize(map);
}

static string ContentType(string file)
{
    return Path.GetExtension(file).ToLowerInvariant() switch
    {
        ".html" => "text/html; charset=utf-8",
        ".css" => "text/css; charset=utf-8",
        ".js" => "application/javascript; charset=utf-8",
        ".json" => "application/json; charset=utf-8",
        ".svg" => "image/svg+xml",
        ".png" => "image/png",
        _ => "application/octet-stream",
    };
}

static void WriteJson(HttpListenerContext ctx, int status, string json)
{
    WriteText(ctx, status, json, "application/json; charset=utf-8");
}

static void WriteText(HttpListenerContext ctx, int status, string body, string type)
{
    WriteBytes(ctx, status, Encoding.UTF8.GetBytes(body), type);
}

static void WriteBytes(HttpListenerContext ctx, int status, byte[] body, string type)
{
    ctx.Response.StatusCode = status;
    ctx.Response.ContentType = type;
    ctx.Response.ContentLength64 = body.Length;
    ctx.Response.OutputStream.Write(body);
    ctx.Response.Close();
}
